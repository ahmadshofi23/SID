<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Auth\AccessScope;
use App\Jobs\RenderLetterPdf;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterRequestLog;
use App\Models\LetterType;
use App\Models\Official;
use App\Models\Resident;
use App\Repositories\LetterRepository;
use App\Repositories\LetterRequestRepository;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Support\ReferenceData;
use App\Support\VillageTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Siklus hidup pengajuan surat — FR-SRT-03 s.d. FR-SRT-12.
 *
 * SELURUH transisi status melewati kelas ini. Tidak ada controller yang boleh
 * menulis `$request->status = ...` sendiri: mesin status, kewenangan peran,
 * pemisahan tugas (RULE-06), dan pencatatan riwayat harus berlaku serentak,
 * dan tiga dari empat hal itu mudah terlupakan bila ditulis ulang per layar.
 */
final class LetterRequestService
{
    public function __construct(
        private readonly LetterRequestRepository $requests,
        private readonly LetterRepository $letters,
        private readonly ResidentRepository $residents,
        private readonly LetterNumberService $numbers,
        private readonly LetterBodyComposer $body,
        private readonly LetterContextBuilder $context,
        private readonly DynamicFormValidator $forms,
        private readonly MutationOfferService $mutationOffers,
        private readonly AuditLogger $audit,
    ) {}

    /* ===================== Pengajuan ===================== */

    /**
     * Buat pengajuan. `$submit` true untuk layanan loket (walk_in), yang
     * langsung masuk antrean tanpa tahap draf.
     *
     * @param  array<string, mixed>  $formInput
     */
    public function create(
        AccessScope $scope,
        LetterType $type,
        string $residentId,
        ?string $purpose,
        array $formInput,
        string $channel = 'online',
        bool $submit = true,
    ): LetterRequest {
        if (! $type->is_active) {
            throw ValidationException::withMessages([
                'letter_type_id' => 'Jenis surat ini sedang tidak aktif.',
            ]);
        }

        /*
         * Pemohon diambil lewat ResidentRepository yang BERLINGKUP.
         *
         * Inilah pemeriksaan kelayakan sebenarnya: Kepala Dusun tidak dapat
         * mengajukan surat untuk warga dusun lain, dan warga tidak dapat
         * mengajukan untuk orang di luar KK-nya — bukan karena ada pengecekan
         * terpisah, tetapi karena penduduk itu tidak terlihat olehnya.
         */
        $resident = $this->residents->findOrFail($residentId);

        $this->guardResidentEligible($resident);
        $this->guardMaritalStatus($type, $resident);

        $form = $this->forms->validate($type, $formInput);

        $existing = $this->requests->openRequestFor($resident->id, $type->id);

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'resident_id' => 'Pemohon masih memiliki pengajuan '.$type->name
                    .' yang sedang diproses (tiket '.$existing->ticket_number.').',
            ]);
        }

        return DB::transaction(function () use ($scope, $type, $resident, $purpose, $form, $channel, $submit): LetterRequest {
            $request = $this->requests->create([
                'letter_type_id' => $type->id,
                'ticket_number' => $this->generateTicket(),
                'resident_id' => $resident->id,
                'requested_by' => $scope->userId === 'system' ? null : $scope->userId,
                'channel' => $channel,
                'purpose' => $purpose,
                'form_data' => $form,
                'status' => LetterRequest::STATUS_DRAFT,
                'current_step' => 0,
            ]);

            $this->writeLog($request, null, LetterRequest::STATUS_DRAFT, 'Pengajuan dibuat');

            if ($submit) {
                $request = $this->submit($request);
            }

            $this->audit->log('letter_request.created', $request, context: [
                'jenis_surat' => $type->code,
                'kanal' => $channel,
            ]);

            return $request;
        });
    }

    /** Kirim pengajuan ke antrean petugas. */
    public function submit(LetterRequest $request, ?string $note = null): LetterRequest
    {
        $this->guardTransitionAllowed($request, LetterRequest::STATUS_SUBMITTED);

        $now = now();

        return $this->transition($request, LetterRequest::STATUS_SUBMITTED, $note, [
            'submitted_at' => $now,
            'due_at' => $this->dueDate($request->letterType, $now),
            'current_step' => 0,
            'revision_note' => null,
        ]);
    }

    /* ===================== Pemeriksaan ===================== */

    /**
     * Verifikasi satu langkah. Alur dua/tiga tahap maju satu langkah per
     * pemanggilan; status baru menjadi `diverifikasi` setelah langkah
     * terakhir terlampaui.
     */
    public function verify(AccessScope $scope, LetterRequest $request, ?string $note = null): LetterRequest
    {
        $this->guardStepAuthority($scope, $request);
        $this->guardNotSelfApproval($scope, $request);

        $type = $request->letterType;
        $nextStep = $request->current_step + 1;

        /*
         * Langkah antara (alur dua/tiga tahap): status TETAP `diajukan`,
         * hanya nomor langkahnya maju.
         *
         * Transisi ini tidak lewat guardTransitionAllowed() karena mesin
         * status memang tidak mengizinkan diajukan → diajukan: ia menjaga
         * perpindahan STATUS, sementara ini perpindahan LANGKAH. Yang
         * dijaga di sini adalah status awalnya harus benar.
         */
        if ($nextStep < $type->verificationStepCount()) {
            if ($request->status !== LetterRequest::STATUS_SUBMITTED) {
                $this->guardTransitionAllowed($request, LetterRequest::STATUS_VERIFIED);
            }

            return $this->transition(
                $request,
                LetterRequest::STATUS_SUBMITTED,
                $note,
                ['current_step' => $nextStep],
                forceLog: true,
            );
        }

        $this->guardTransitionAllowed($request, LetterRequest::STATUS_VERIFIED);

        return $this->transition($request, LetterRequest::STATUS_VERIFIED, $note, [
            'current_step' => $nextStep,
        ]);
    }

    public function requestRevision(AccessScope $scope, LetterRequest $request, string $note): LetterRequest
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages([
                'note' => 'Catatan revisi wajib diisi — pemohon perlu tahu apa yang harus diperbaiki.',
            ]);
        }

        $this->guardStepAuthority($scope, $request);
        $this->guardTransitionAllowed($request, LetterRequest::STATUS_NEEDS_REVISION);

        return $this->transition($request, LetterRequest::STATUS_NEEDS_REVISION, $note, [
            'revision_note' => $note,
            'current_step' => 0,
        ]);
    }

    public function reject(AccessScope $scope, LetterRequest $request, string $reason): LetterRequest
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan penolakan wajib diisi.',
            ]);
        }

        $this->guardStepAuthority($scope, $request);
        $this->guardTransitionAllowed($request, LetterRequest::STATUS_REJECTED);

        return $this->transition($request, LetterRequest::STATUS_REJECTED, $reason, [
            'rejection_reason' => $reason,
            'completed_at' => now(),
        ]);
    }

    public function cancel(LetterRequest $request, ?string $reason = null): LetterRequest
    {
        $this->guardTransitionAllowed($request, LetterRequest::STATUS_CANCELLED);

        return $this->transition($request, LetterRequest::STATUS_CANCELLED, $reason, [
            'completed_at' => now(),
        ]);
    }

    /* ===================== Penerbitan ===================== */

    /**
     * Terbitkan surat — RULE-04, ADR-001, ADR-003.
     *
     * Titik paling kritis di seluruh modul. Satu transaksi mencakup:
     * pengambilan nomor, perenderan, penyimpanan snapshot, dan perubahan
     * status. Bila salah satu gagal, SEMUANYA dibatalkan — khususnya
     * nomornya, agar tidak ada nomor terpakai tanpa surat.
     */
    public function issue(
        AccessScope $scope,
        LetterRequest $request,
        ?Official $signer = null,
        ?string $note = null,
    ): Letter {
        $this->guardTransitionAllowed($request, LetterRequest::STATUS_ISSUED);
        $this->guardIssueAuthority($scope);
        $this->guardNotSelfApproval($scope, $request);

        if ($request->letter !== null) {
            throw new ConflictHttpException(
                'Pengajuan ini sudah memiliki surat terbit: '.$request->letter->letter_number
            );
        }

        $signer ??= $this->defaultSigner();
        $type = $request->letterType;

        return DB::transaction(function () use ($scope, $request, $type, $signer, $note): Letter {
            $issuedAt = now();

            [$sequence, $number] = $this->numbers->allocate($type, $issuedAt);

            $verificationCode = Letter::generateVerificationCode();

            $context = $this->context->build(
                $request, $signer, $issuedAt, $number, $verificationCode
            );

            $letter = $this->letters->create([
                'request_id' => $request->id,
                'letter_type_id' => $type->id,
                'letter_number' => $number,
                'sequence_number' => $sequence,
                'issued_at' => $issuedAt,
                /*
                 * Tahun DITULIS di sini, tidak dihitung basis data dari
                 * issued_at: ia harus memakai kalender desa yang sama dengan
                 * penomoran, agar indeks unik uq_letters_seq menjaga pasangan
                 * tahun yang benar di pergantian tahun (lihat migrasi
                 * 2026_01_03_000200).
                 */
                'issued_year' => VillageTime::year($issuedAt),
                'issued_by' => $scope->userId,
                'signer_official_id' => $signer->id,
                'signer_name' => $signer->name,
                'signer_position' => $signer->position,
                /*
                 * Dihitung dari tanggal LOKAL, sama seperti yang tercetak di
                 * surat (LetterContextBuilder). Menghitungnya dari UTC akan
                 * membuat tanggal di basis data berbeda satu hari dari
                 * tanggal yang dibaca warga pada surat di tangannya.
                 */
                'valid_until' => $type->validity_months !== null
                    ? VillageTime::local($issuedAt)
                        ->addMonths($type->validity_months)->toDateString()
                    : null,
                'snapshot' => $this->context->toSnapshot($context, $request),
                /*
                 * BADAN SURAT UTUH disimpan, bukan hanya isi pokoknya.
                 *
                 * Halaman cetak tidak boleh merakit ulang pembuka dan blok
                 * identitas: menyuntingnya akan mengubah tampilan surat yang
                 * sudah terbit bertahun-tahun lalu (ADR-003).
                 */
                'rendered_html' => $this->body->compose($request, $context),
                'verification_code' => $verificationCode,
                'status' => Letter::STATUS_VALID,
            ]);

            $this->transition($request, LetterRequest::STATUS_ISSUED, $note, [
                'completed_at' => $issuedAt,
            ]);

            /*
             * Setiap surat memuat NIK LENGKAP pada blok identitasnya
             * (LetterBodyComposer) — memang begitu surat keterangan desa,
             * karena penerimanya mencocokkannya dengan KTP.
             *
             * Pengungkapan itu WAJIB tercatat (RULE-07). Dicatat tanpa
             * syarat, bukan hanya ketika template memuat {{nik}} seperti
             * sebelumnya: blok identitas selalu ada, jadi syarat itu
             * membuat sebagian pengungkapan lolos tanpa jejak.
             */
            $this->audit->logPiiReveal($request->resident, 'nik');

            /*
             * TAWARAN mutasi untuk SKL/SKM — docs/13 §5.4 & §5.5, KEP-015.
             *
             * Hanya sebuah baris "menunggu": tidak ada status penduduk yang
             * berubah di sini, dan tidak ada apa pun pada penerbitan yang
             * bergantung padanya. Dibuat DI DALAM transaksi ini supaya setiap
             * SKL/SKM yang terbit pasti punya tawarannya — tawaran yang hilang
             * berarti kelahiran/kematian yang diam-diam tidak pernah masuk
             * basis data penduduk, yaitu persis masalah yang hendak dicegah.
             */
            $this->mutationOffers->createFor($request, $letter);

            $this->audit->log('letter.issued', $letter, context: [
                'nomor_surat' => $number,
                'jenis_surat' => $type->code,
                'tiket' => $request->ticket_number,
            ]);

            /*
             * PDF dirender di queue, SETELAH transaksi ini commit —
             * ADR-003 & docs/04 §6 ("efek samping ditunda lewat queue
             * setelah commit").
             *
             * afterCommit() bukan kerapian: tanpa itu, worker dapat
             * mengambil pekerjaan ini sebelum barisnya terlihat di basis
             * data, dan job akan gagal mencari surat yang baru saja
             * diterbitkan — kegagalan yang hanya muncul di bawah beban.
             *
             * Kegagalan render TIDAK menggagalkan penerbitan: nomor surat
             * sudah sah, isinya sudah tersimpan, dan suratnya sudah dapat
             * dicetak dari layar. Yang hilang hanyalah berkas PDF-nya, dan
             * itu dapat diulang dari halaman surat.
             */
            RenderLetterPdf::dispatch($letter->id)->afterCommit();

            return $letter;
        });
    }

    /**
     * Batalkan surat terbit — FR-SRT-12, RULE-05.
     *
     * Suratnya TIDAK dihapus dan nomornya TIDAK dilepaskan untuk dipakai
     * ulang. Nomor yang pernah terbit sudah ada di tangan warga dan mungkin
     * sudah dicatat pihak lain; memakainya kembali akan menghasilkan dua
     * dokumen berbeda dengan nomor sama.
     */
    public function cancelLetter(Letter $letter, string $reason, ?string $actorId): Letter
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        if ($letter->status === Letter::STATUS_CANCELLED) {
            throw new ConflictHttpException('Surat ini sudah dibatalkan.');
        }

        $letter->status = Letter::STATUS_CANCELLED;
        $letter->cancelled_at = now();
        $letter->cancelled_by = $actorId;
        $letter->cancel_reason = $reason;
        $letter->save();

        $this->audit->log('letter.cancelled', $letter, context: [
            'nomor_surat' => $letter->letter_number,
            'alasan' => $reason,
        ]);

        return $letter;
    }

    /* ===================== Pagar ===================== */

    private function guardTransitionAllowed(LetterRequest $request, string $to): void
    {
        if (! $request->canTransitionTo($to)) {
            $labels = LetterRequest::statusLabels();

            /*
             * 409, bukan 422 (FR-SRT-07). Permintaannya sah secara bentuk;
             * yang tidak cocok adalah keadaan data saat ini — biasanya karena
             * petugas lain sudah memprosesnya lebih dulu di tab sebelah.
             */
            throw new ConflictHttpException(sprintf(
                'Pengajuan berstatus "%s" tidak dapat diubah menjadi "%s". '
                .'Kemungkinan sudah diproses petugas lain — muat ulang halaman.',
                $labels[$request->status] ?? $request->status,
                $labels[$to] ?? $to,
            ));
        }
    }

    /** Peran yang berwenang pada langkah pemeriksaan saat ini. */
    private function guardStepAuthority(AccessScope $scope, LetterRequest $request): void
    {
        $roles = $request->letterType->rolesForStep($request->current_step);

        if ($scope->hasRole('admin_desa')) {
            return;
        }

        foreach ($roles as $role) {
            if ($scope->hasRole($role)) {
                return;
            }
        }

        abort(403, 'Langkah pemeriksaan ini bukan kewenangan Anda.');
    }

    private function guardIssueAuthority(AccessScope $scope): void
    {
        if ($scope->hasRole('kepala_desa') || $scope->hasRole('admin_desa')) {
            return;
        }

        abort(403, 'Penerbitan surat hanya oleh Kepala Desa atau Admin Desa.');
    }

    /**
     * Pemisahan tugas — RULE-06.
     *
     * Pengecualian walk_in BUKAN kelonggaran yang dilupakan: di loket desa
     * kecil, operator yang mengetik pengajuan dan admin yang menerbitkan
     * sering orang yang sama, dan memblokirnya akan membuat sistem tidak
     * dapat dipakai sama sekali. Yang kami lakukan adalah MENCATATNYA, agar
     * terlihat saat audit.
     */
    private function guardNotSelfApproval(AccessScope $scope, LetterRequest $request): void
    {
        if ($request->requested_by === null || $scope->userId !== $request->requested_by) {
            return;
        }

        if ($request->channel === 'walk_in') {
            $this->audit->log('letter_request.self_approved', $request, context: [
                'tiket' => $request->ticket_number,
                'sebab' => 'layanan loket (walk_in) — dikecualikan RULE-06',
            ]);

            return;
        }

        abort(403, 'Anda tidak dapat menyetujui pengajuan yang Anda buat sendiri (RULE-06).');
    }

    /**
     * Penduduk yang sudah meninggal atau pindah tidak dapat mengajukan surat.
     *
     * Pemeriksaan ini terlihat berlebihan sampai ia terjadi: surat keterangan
     * domisili untuk orang yang sudah pindah adalah dokumen palsu yang
     * dikeluarkan kantor desa sendiri.
     */
    private function guardResidentEligible(Resident $resident): void
    {
        if ($resident->status !== Resident::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'resident_id' => 'Penduduk berstatus "'.$resident->status
                    .'" tidak dapat mengajukan surat. Catat mutasi lebih dulu bila datanya keliru.',
            ]);
        }
    }

    /**
     * Prasyarat status perkawinan — docs/13 §5.7.
     *
     * SKBM hanya untuk yang berstatus "Belum Kawin"; SKJD hanya untuk yang
     * cerai hidup atau cerai mati. Tanpa pagar ini, kantor desa dapat
     * menerbitkan surat yang BERTENTANGAN dengan data desa sendiri — dan
     * data itulah yang akan dicocokkan instansi tujuan.
     *
     * Pesan galatnya mengarahkan ke perbaikan data, bukan menyalahkan
     * pemohon: dalam praktik, penyebab paling sering adalah data
     * kependudukan yang belum diperbarui setelah warga menikah.
     */
    private function guardMaritalStatus(LetterType $type, Resident $resident): void
    {
        $allowed = $type->form_schema['requires_marital_status'] ?? null;

        if (! is_array($allowed) || $allowed === []) {
            return;
        }

        if (in_array($resident->marital_status_code, $allowed, true)) {
            return;
        }

        $labels = ReferenceData::get('marital_statuses');
        $current = $labels[$resident->marital_status_code] ?? 'belum diisi';

        throw ValidationException::withMessages([
            'resident_id' => 'Status perkawinan '.$resident->full_name.' tercatat sebagai "'
                .$current.'", sehingga '.$type->name.' tidak dapat diterbitkan. '
                .'Perbaiki data kependudukan terlebih dahulu bila tidak sesuai.',
        ]);
    }

    /* ===================== Pembantu ===================== */

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(
        LetterRequest $request,
        string $to,
        ?string $note,
        array $attributes = [],
        bool $forceLog = false,
    ): LetterRequest {
        $from = $request->status;

        return DB::transaction(function () use ($request, $from, $to, $note, $attributes, $forceLog): LetterRequest {
            $request->fill([...$attributes, 'status' => $to]);
            $request->save();

            if ($from !== $to || $forceLog) {
                $this->writeLog($request, $from, $to, $note);
            }

            return $request->refresh();
        });
    }

    private function writeLog(LetterRequest $request, ?string $from, string $to, ?string $note): void
    {
        $actor = auth()->user();

        LetterRequestLog::query()->create([
            'request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'step' => $request->current_step,
            'actor_id' => $actor?->id,
            'actor_name' => $actor === null ? 'sistem' : $actor->name,
            'note' => $note,
        ]);
    }

    /**
     * Nomor tiket — identitas yang dipegang warga sebelum surat terbit.
     *
     * Formatnya singkat dan dapat dibacakan di telepon. Bagian acaknya
     * mencegah seseorang menerka tiket milik orang lain dan melacak
     * pengajuannya di portal publik.
     */
    private function generateTicket(): string
    {
        return 'T'.now()->format('ymd').'-'.Str::upper(Str::random(5));
    }

    /**
     * Batas waktu dari SLA — HARI KERJA, pada kalender desa.
     *
     * Pengajuan Jumat sore dengan SLA satu hari jatuh tempo Senin, bukan
     * Sabtu. Memakai hari kalender akan membuat separuh antrean tampak
     * terlambat setiap Senin pagi, dan laporan SLA kehilangan maknanya.
     *
     * Perhitungannya diserahkan ke VillageTime karena "akhir hari" di UTC
     * jatuh pukul 07.00 pagi berikutnya di Indonesia — selisih yang membuat
     * surat terlambat tercatat sebagai tepat waktu.
     */
    private function dueDate(LetterType $type, Carbon $from): Carbon
    {
        return VillageTime::endOfWorkingDay($from, $type->sla_days);
    }

    /**
     * Penanda tangan bawaan: Kepala Desa (docs/13 §4).
     */
    private function defaultSigner(): Official
    {
        $signer = Official::query()
            ->where('is_active', true)
            ->where('can_sign_letter', true)
            ->orderByRaw("CASE WHEN position_code = 'kepala_desa' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->first();

        if ($signer === null) {
            /*
             * Menolak terbit, bukan memakai nama kosong. Surat tanpa
             * penanda tangan tidak sah, dan kesalahan ini jauh lebih mudah
             * diperbaiki sekarang daripada setelah ratusan surat tercetak
             * dengan baris tanda tangan kosong.
             */
            throw ValidationException::withMessages([
                'signer_official_id' => 'Belum ada pejabat penanda tangan aktif. '
                    .'Atur di Pengaturan › Perangkat Desa sebelum menerbitkan surat.',
            ]);
        }

        return $signer;
    }
}
