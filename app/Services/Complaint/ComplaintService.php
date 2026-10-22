<?php

declare(strict_types=1);

namespace App\Services\Complaint;

use App\Auth\AccessScope;
use App\Models\Complaint;
use App\Models\ComplaintAction;
use App\Models\Setting;
use App\Models\User;
use App\Repositories\ComplaintRepository;
use App\Services\Audit\AuditLogger;
use App\Support\Pii\PiiCipher;
use App\Support\VillageTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Siklus hidup pengaduan — FR-PGD-01 s.d. FR-PGD-08, UC-13, UC-14.
 *
 * SELURUH perubahan status melewati kelas ini, dengan alasan yang sama seperti
 * LetterRequestService: mesin status, kewenangan peran, pencatatan riwayat,
 * dan notifikasi ke pelapor harus berlaku serentak — dan tiga dari empat hal
 * itu mudah terlupakan bila ditulis ulang per layar.
 *
 * Satu hal yang HANYA ada di sini dan tidak ada padanannya pada persuratan:
 * RULE-09. Pengaduan anonim tidak boleh menyimpan identitas pelapor, dan
 * kelas ini adalah satu-satunya tempat identitas itu dapat masuk ke basis
 * data. CHECK `chk_complaints_anon` adalah jaring pengamannya, bukan
 * penggantinya: galat basis data di hadapan warga yang sedang melapor bukan
 * jawaban yang pantas.
 */
final class ComplaintService
{
    public function __construct(
        private readonly ComplaintRepository $complaints,
        private readonly ComplaintNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly PiiCipher $pii,
    ) {}

    /* ============================== Pengajuan ============================= */

    /**
     * Catat pengaduan baru.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(AccessScope $scope, array $input, ?string $ip = null): Complaint
    {
        $anonymous = (bool) ($input['is_anonymous'] ?? false);

        /*
         * Alamat IP diubah menjadi sidik jari ber-kunci SEKALI, di sini, dan
         * yang mentahnya tidak pernah menyentuh basis data. Lihat keterangan
         * panjang pada kolom `submitted_ip_hash` di migrasinya.
         */
        $ipHash = $ip === null ? null : $this->pii->hash($ip);

        $this->guardNotFlooding($ipHash);

        $now = now();

        /*
         * RULE-09 ditegakkan dengan MENGHILANGKAN jalurnya, bukan dengan
         * memeriksa lalu menolak.
         *
         * Ketiga kolom identitas dipaksa null di sini, sehingga tidak ada
         * nilai dari permintaan HTTP yang dapat sampai ke basis data ketika
         * pengaduan ditandai anonim — bahkan bila formulir mengirimnya, bahkan
         * bila validasi di atas sini suatu hari dilonggarkan. Pemeriksaan yang
         * menolak dapat dilewati oleh jalur baru; penghilangan tidak.
         */
        $identity = $anonymous ? [
            'is_anonymous' => true,
            'reporter_user_id' => null,
            'reporter_name' => null,
            'reporter_phone' => null,
        ] : [
            'is_anonymous' => false,
            'reporter_user_id' => $scope->userId === 'system' ? null : $scope->userId,
            'reporter_name' => $this->nonEmpty($input['reporter_name'] ?? null),
            'reporter_phone' => $this->nonEmpty($input['reporter_phone'] ?? null),
        ];

        return DB::transaction(function () use ($input, $identity, $ipHash, $now, $anonymous): Complaint {
            $complaint = $this->complaints->create([
                ...$identity,
                'ticket_number' => $this->generateTicket(),
                'tracking_code' => $this->generateTrackingCode(),
                'category_code' => (string) $input['category_code'],
                'title' => (string) $input['title'],
                'body' => (string) $input['body'],
                'region_id' => $this->nonEmpty($input['region_id'] ?? null),
                'location_text' => $this->nonEmpty($input['location_text'] ?? null),
                'latitude' => $this->nonEmpty($input['latitude'] ?? null),
                'longitude' => $this->nonEmpty($input['longitude'] ?? null),
                'status' => Complaint::STATUS_NEW,
                'priority' => Complaint::PRIORITY_NORMAL,
                'triage_due_at' => $this->triageDueDate($now),
                'submitted_ip_hash' => $ipHash,
            ]);

            $this->writeAction($complaint, null, Complaint::STATUS_NEW, 'Pengaduan diterima sistem.');

            /*
             * Pemberitahuan ke petugas penerima, BUKAN ke pelapor: pelapor
             * baru saja menekan tombol kirim dan sedang melihat halaman yang
             * memuat kode pelacakannya. Memberitahunya lewat lonceng tentang
             * hal yang ia sendiri baru lakukan hanya melatih orang
             * mengabaikan lonceng itu.
             */
            $this->notifier->awaitingTriage($complaint);

            /*
             * AUDIT LOG PENGADUAN ANONIM TIDAK MENYEBUT PENGADUANNYA, dan ini
             * bukan kehati-hatian berlebihan melainkan menutup jalan pintas
             * RULE-09 yang paling mudah terlewat.
             *
             * AuditLogger::log() dengan model menyimpan `entity_id` BESERTA
             * `actor_id`, `actor_name`, `actor_roles`, dan `ip_address`
             * pembuatnya. Pada pengaduan anonim yang dikirim warga yang sedang
             * masuk, satu baris itu berisi tepat tautan yang dilarang ada di
             * tabel `complaints` — lengkap dengan namanya. CHECK
             * `chk_complaints_anon` tidak menolong apa pun di sini, karena
             * yang dijaganya tabel pengaduan, bukan audit log.
             *
             * Karena itu pengaduan anonim dicatat TANPA model dan TANPA
             * pelaku: yang tersisa hanyalah fakta bahwa sebuah pengaduan
             * anonim masuk, yang cukup untuk memantau volume dan
             * penyalahgunaan tanpa dapat ditautkan ke siapa pun.
             *
             * Dan pada keduanya, audit log TIDAK memuat judul maupun isi
             * pengaduan. Nomor tiket sudah cukup untuk menelusuri, sementara
             * judul yang tersalin ke log akan membocorkan isi pengaduan anonim
             * kepada siapa pun yang berhak membacanya — yaitu Admin Desa,
             * yang justru paling sering menjadi pihak yang diadukan.
             */
            if ($anonymous) {
                $this->audit->logWithoutActor('complaint.created_anonymous', [
                    'kategori' => $complaint->category_code,
                ]);
            } else {
                $this->audit->log('complaint.created', $complaint, context: [
                    'tiket' => $complaint->ticket_number,
                    'kategori' => $complaint->category_code,
                ]);
            }

            return $complaint;
        });
    }

    /* ============================== Transisi ============================== */

    /** Triase: pengaduan diakui sebagai urusan desa — FR-PGD-03. */
    public function accept(AccessScope $scope, Complaint $complaint, ?string $note = null): Complaint
    {
        $this->guardCanFollowUp($scope);
        $this->guardTransitionAllowed($complaint, Complaint::STATUS_ACCEPTED);

        $now = now();

        /*
         * Tenggat penyelesaian dihitung dari SAAT TRIASE, bukan dari saat
         * pengaduan masuk. Pengaduan yang didiamkan sepuluh hari lalu baru
         * ditriase tidak lantas tersisa empat hari — itu akan menghukum
         * petugas yang akhirnya menanganinya dan menyembunyikan keterlambatan
         * yang sebenarnya terjadi di tahap triase. Keterlambatan triase punya
         * catatannya sendiri di `triage_due_at` (KEP-018), dan itulah yang
         * harus dibaca bila angka SLA desa terlihat buruk.
         */
        return $this->transition($complaint, Complaint::STATUS_ACCEPTED, $note, [
            'due_at' => $this->resolutionDueDate($now),
        ]);
    }

    /** Tindak lanjut sedang berjalan. */
    public function startWork(AccessScope $scope, Complaint $complaint, ?string $note = null): Complaint
    {
        $this->guardCanFollowUp($scope);
        $this->guardTransitionAllowed($complaint, Complaint::STATUS_IN_PROGRESS);

        return $this->transition($complaint, Complaint::STATUS_IN_PROGRESS, $note);
    }

    /** Selesai, dengan narasi hasil yang dibaca pelapor — FR-PGD-05. */
    public function resolve(AccessScope $scope, Complaint $complaint, string $resolution): Complaint
    {
        $this->guardCanFollowUp($scope);
        $this->guardTransitionAllowed($complaint, Complaint::STATUS_DONE);

        $resolution = trim($resolution);

        if ($resolution === '') {
            throw ValidationException::withMessages([
                'resolution' => 'Tuliskan hasil penanganannya. Pelapor membaca kalimat ini.',
            ]);
        }

        return $this->transition($complaint, Complaint::STATUS_DONE, $resolution, [
            'resolution' => $resolution,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Ditolak — alasan WAJIB (FR-PGD-03).
     *
     * Diperiksa di sini dan juga di tingkat basis data
     * (`chk_complaints_tolak_beralasan`). Yang di sini ada agar warga menerima
     * pesan validasi yang dapat dibaca; yang di basis data ada agar aturannya
     * tetap berlaku untuk jalur yang belum ada hari ini.
     */
    public function reject(AccessScope $scope, Complaint $complaint, string $reason): Complaint
    {
        $this->guardCanFollowUp($scope);
        $this->guardTransitionAllowed($complaint, Complaint::STATUS_REJECTED);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'resolution' => 'Pengaduan tidak dapat ditolak tanpa alasan tertulis.',
            ]);
        }

        return $this->transition($complaint, Complaint::STATUS_REJECTED, $reason, [
            'resolution' => $reason,
            'resolved_at' => now(),
        ]);
    }

    /* ============================= Penugasan ============================== */

    /** Tugaskan ke petugas; penerima tugas diberi tahu — FR-PGD-04. */
    public function assign(
        AccessScope $scope,
        Complaint $complaint,
        ?User $assignee,
        ?string $note = null,
    ): Complaint {
        if (! $scope->can('pengaduan.tugaskan')) {
            abort(403, 'Anda tidak berwenang menugaskan pengaduan.');
        }

        if ($assignee !== null && ! $assignee->hasPermission('pengaduan.tindaklanjut')) {
            /*
             * Menugaskan kepada orang yang tidak dapat menindaklanjuti berarti
             * pengaduan itu resmi "ada yang menangani" sementara tidak ada
             * seorang pun yang dapat menyentuhnya. Itu kelas kegagalan yang
             * paling sulit terlihat, karena di layar semuanya tampak beres.
             */
            throw ValidationException::withMessages([
                'assigned_to' => 'Pengguna ini tidak punya kewenangan menindaklanjuti pengaduan.',
            ]);
        }

        $previous = $complaint->assigned_to;

        $complaint->forceFill([
            'assigned_to' => $assignee?->id,
            'assigned_at' => $assignee === null ? null : now(),
        ])->save();

        $this->writeAction(
            $complaint,
            $complaint->status,
            $complaint->status,
            $assignee === null
                ? 'Penugasan dicabut.'
                : 'Ditugaskan kepada '.$assignee->name.'.'.($note === null ? '' : ' '.$note),
            visibleToReporter: false,
        );

        if ($assignee !== null && $assignee->id !== $previous) {
            $this->notifier->assigned($complaint->refresh(), $assignee);
        }

        $this->audit->log('complaint.assigned', $complaint, context: [
            'tiket' => $complaint->ticket_number,
            'kepada' => $assignee?->name,
        ]);

        return $complaint->refresh();
    }

    /* ============================ Tindak lanjut =========================== */

    /**
     * Catatan tindak lanjut tanpa mengubah status — FR-PGD-05.
     *
     * `$visibleToReporter` bawaannya true: catatan yang disembunyikan dari
     * pelapor harus selalu merupakan pilihan sadar, bukan keadaan bawaan.
     */
    public function addFollowUp(
        AccessScope $scope,
        Complaint $complaint,
        string $note,
        bool $visibleToReporter = true,
    ): ComplaintAction {
        $this->guardCanFollowUp($scope);

        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => 'Catatan tindak lanjut tidak boleh kosong.',
            ]);
        }

        $action = $this->writeAction(
            $complaint,
            $complaint->status,
            $complaint->status,
            $note,
            visibleToReporter: $visibleToReporter,
        );

        if ($visibleToReporter) {
            $this->notifier->followUp($complaint, $note);
        }

        return $action;
    }

    /** Publikasikan ringkasan ke portal — FR-PGD-07, opt-in per kasus. */
    public function setPublished(AccessScope $scope, Complaint $complaint, bool $published): Complaint
    {
        if (! $scope->can('pengaduan.tindaklanjut')) {
            abort(403);
        }

        if ($published && $complaint->status !== Complaint::STATUS_DONE) {
            throw ValidationException::withMessages([
                'is_published' => 'Hanya pengaduan yang sudah selesai dapat dipublikasikan.',
            ]);
        }

        $complaint->forceFill(['is_published' => $published])->save();

        $this->audit->log('complaint.published', $complaint, context: [
            'tiket' => $complaint->ticket_number,
            'terbit' => $published,
        ]);

        return $complaint->refresh();
    }

    /* ============================== Eskalasi ============================== */

    /**
     * Naikkan prioritas pengaduan yang melewati tenggatnya — FR-PGD-06.
     *
     * Dipanggil perintah `sid:eskalasi-pengaduan`. Yang diubah HANYA prioritas
     * dan notifikasinya; status "terlambat" yang terlihat di antrean dan
     * dashboard tetap dihitung dari kueri (ComplaintRepository::whereOverdue()).
     * Pembagian itu disengaja: penjadwal yang mati membuat desa kehilangan
     * PERINGATAN, bukan kehilangan kebenaran — dan tidak ada angka di layar
     * yang menjadi salah karena cron tidak jalan.
     *
     * Idempoten: pengaduan yang sudah berprioritas tinggi tidak terambil lagi,
     * sehingga perintah yang berjalan dua kali sehari tidak mengirim dua
     * notifikasi untuk keterlambatan yang sama.
     */
    public function escalate(Complaint $complaint): bool
    {
        if ($complaint->priority === Complaint::PRIORITY_HIGH || ! $complaint->isOverdue()) {
            return false;
        }

        $complaint->forceFill(['priority' => Complaint::PRIORITY_HIGH])->save();

        $this->writeAction(
            $complaint,
            $complaint->status,
            $complaint->status,
            $complaint->status === Complaint::STATUS_NEW
                ? 'Eskalasi otomatis: melewati batas triase 2 hari kerja.'
                : 'Eskalasi otomatis: melewati batas penyelesaian.',
            visibleToReporter: false,
        );

        $this->notifier->escalated($complaint->refresh());

        return true;
    }

    /* ============================== Internal ============================== */

    /**
     * SATU-SATUNYA pintu perubahan status.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(
        Complaint $complaint,
        string $to,
        ?string $note,
        array $attributes = [],
    ): Complaint {
        $from = $complaint->status;

        return DB::transaction(function () use ($complaint, $from, $to, $note, $attributes): Complaint {
            $complaint->forceFill([...$attributes, 'status' => $to])->save();

            $this->writeAction($complaint, $from, $to, $note ?? 'Status diubah.');

            /*
             * SATU-SATUNYA tempat notifikasi status pengaduan dikirim.
             *
             * Seluruh perubahan status melewati transition(), jadi memasangnya
             * di sini berarti tidak ada jalur yang dapat mengubah status tanpa
             * memberi tahu pelapornya — termasuk jalur yang ditambahkan nanti
             * oleh orang yang tidak tahu notifikasi ini ada. Pola yang sama,
             * dan untuk alasan yang sama, seperti LetterRequestService.
             *
             * Dikirim DI DALAM transaksi dengan sengaja: notifikasi untuk
             * transisi yang kemudian di-rollback jauh lebih buruk daripada
             * notifikasi yang tidak terkirim.
             */
            $this->notifier->statusChanged($complaint, $from);

            $this->audit->log('complaint.status_changed', $complaint, context: [
                'tiket' => $complaint->ticket_number,
                'dari' => $from,
                'ke' => $to,
            ]);

            return $complaint->refresh();
        });
    }

    private function writeAction(
        Complaint $complaint,
        ?string $from,
        ?string $to,
        string $note,
        bool $visibleToReporter = true,
    ): ComplaintAction {
        $actor = auth()->user();

        return ComplaintAction::query()->create([
            'complaint_id' => $complaint->id,
            'actor_id' => $actor?->id,
            'actor_name' => $actor === null ? 'sistem' : $actor->name,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'is_visible_to_reporter' => $visibleToReporter,
        ]);
    }

    private function guardCanFollowUp(AccessScope $scope): void
    {
        if (! $scope->can('pengaduan.tindaklanjut')) {
            abort(403, 'Anda tidak berwenang menindaklanjuti pengaduan.');
        }
    }

    private function guardTransitionAllowed(Complaint $complaint, string $to): void
    {
        if ($complaint->canTransitionTo($to)) {
            return;
        }

        $labels = Complaint::statusLabels();

        throw ValidationException::withMessages([
            'status' => sprintf(
                'Pengaduan berstatus %s tidak dapat diubah menjadi %s.',
                $labels[$complaint->status] ?? $complaint->status,
                $labels[$to] ?? $to,
            ),
        ]);
    }

    /**
     * Pembatasan laju per IP — FR-PGD-08.
     *
     * Lapis KEDUA, di belakang middleware `throttle` pada rutenya. Middleware
     * menghitung permintaan HTTP dan lupa setiap kali cache dibersihkan;
     * hitungan ini membaca baris yang benar-benar tersimpan, sehingga batas
     * harian tetap berlaku melewati penyetelan ulang cache dan melewati
     * jeda yang cukup panjang untuk mengelabui jendela middleware.
     *
     * Batasnya longgar dengan sengaja: satu keluarga di balik satu sambungan
     * desa dapat melapor beberapa kali sehari, dan memblokir mereka jauh lebih
     * merugikan daripada menerima beberapa pengaduan berlebih.
     */
    private function guardNotFlooding(?string $ipHash): void
    {
        if ($ipHash === null) {
            return;
        }

        $max = (int) config('sid.complaints.max_per_ip_per_day', 10);

        if ($this->complaints->countFromIpSince($ipHash, now()->subDay()) >= $max) {
            throw ValidationException::withMessages([
                'body' => 'Sudah terlalu banyak pengaduan dikirim dari jaringan ini '
                    .'dalam 24 jam terakhir. Silakan coba lagi besok, atau sampaikan '
                    .'langsung ke kantor desa.',
            ]);
        }
    }

    /**
     * Nomor tiket — identitas administratif yang beredar di berkas desa.
     *
     * Formatnya singkat dan dapat dibacakan di telepon, mengikuti nomor tiket
     * surat. Ia TIDAK dipakai untuk melacak pengaduan: lihat alasannya pada
     * keterangan `tracking_code` di migrasinya.
     */
    private function generateTicket(): string
    {
        do {
            $ticket = 'P'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while ($this->complaints->ticketExists($ticket));

        return $ticket;
    }

    /**
     * ABJAD KODE PELACAKAN — tanpa huruf dan angka yang tertukar di kertas.
     *
     * Dibuang: huruf I, L, O, U dan angka 0, 1. Keempat pasangan I/1, L/1,
     * O/0, dan U/V adalah kekeliruan yang pasti terjadi, karena kode ini
     * memang ditulis ulang dengan tangan — warga desa mencatatnya di buku
     * atau di balik struk, bukan menyimpannya di pengelola kata sandi.
     *
     * Ini bukan kenyamanan melainkan syarat agar janji FR-PGD-02 benar-benar
     * dapat ditepati: salah satu huruf berarti pelapor ANONIM kehilangan
     * pengaduannya untuk selamanya, dan tidak ada seorang pun — termasuk
     * petugas desa — yang dapat mengembalikannya kepadanya.
     *
     * Sisa 30 karakter × 16 posisi ≈ 78 bit, masih jauh di atas yang
     * dibutuhkan untuk menahan terkaan.
     */
    private const TRACKING_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Kode pelacakan — RAHASIA yang dipegang pelapor.
     *
     * Enam belas karakter acak, jauh lebih panjang dari nomor tiket, karena
     * ia adalah satu-satunya faktor yang membuka pengaduan anonim: tidak ada
     * faktor kedua yang dapat diminta dari pelapor anonim tanpa menghapus
     * keanoniman itu sendiri — tanggal lahir adalah identitas. Panjang inilah
     * yang menggantikannya, dan karena itu ia tidak boleh pernah muncul di
     * audit log, notifikasi, maupun daftar petugas.
     *
     * random_int() dan bukan Str::random(): yang dibutuhkan di sini adalah
     * abjad tertentu (lihat TRACKING_ALPHABET), dan keduanya sama-sama
     * bersumber dari pembangkit acak kriptografis.
     */
    private function generateTrackingCode(): string
    {
        $max = strlen(self::TRACKING_ALPHABET) - 1;

        do {
            $code = '';

            for ($i = 0; $i < 16; $i++) {
                $code .= self::TRACKING_ALPHABET[random_int(0, $max)];
            }
        } while ($this->complaints->trackingCodeExists($code));

        return $code;
    }

    private function triageDueDate(Carbon $from): Carbon
    {
        return VillageTime::endOfWorkingDay(
            $from,
            (int) Setting::get('complaint.triage_sla_days', 2),
        );
    }

    private function resolutionDueDate(Carbon $from): Carbon
    {
        return VillageTime::endOfWorkingDay(
            $from,
            (int) Setting::get('complaint.resolution_sla_days', 14),
        );
    }

    /**
     * String kosong menjadi NULL.
     *
     * Formulir HTML mengirim isian yang tidak diisi sebagai string kosong,
     * bukan null. Dibiarkan apa adanya, `location_text` kosong akan tersimpan
     * sebagai "" — yang lolos setiap pemeriksaan `!== null` dan muncul di
     * layar sebagai lokasi bernama kosong.
     */
    private function nonEmpty(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        // Titik koordinat dapat tiba sebagai angka, bukan string.
        return is_int($value) || is_float($value) ? (string) $value : null;
    }
}
