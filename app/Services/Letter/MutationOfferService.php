<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\Letter;
use App\Models\LetterMutationOffer;
use App\Models\LetterRequest;
use App\Models\Resident;
use App\Models\ResidentMutation;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Population\MutationService;
use App\Services\Population\ResidentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tawaran pencatatan mutasi dari SKL/SKM — docs/13 §5.4 & §5.5, KEP-015.
 *
 * MASALAH YANG DIPECAHKAN modul ini bukan kenyamanan, melainkan selisih data.
 * Sebelum ini, kelahiran dan kematian tercatat di SURAT tetapi tidak di basis
 * data penduduk: kantor desa menerbitkan surat keterangan kematian pagi itu,
 * lalu warga yang sama tetap terhitung hidup di laporan akhir bulan. Itulah
 * sumber selisih data paling umum di desa, dan ia tidak pernah muncul sebagai
 * galat — hanya sebagai angka yang tidak dapat dijelaskan.
 *
 * MENGAPA MENAWARKAN, BUKAN MENCATAT SENDIRI.
 * Mutasi mengubah status penduduk, dan untuk kematian ia tidak dapat ditarik
 * kembali lewat antarmuka (status `meninggal` membuat warga tidak dapat lagi
 * mengajukan surat). Surat yang terbit atas data yang keliru masih dapat
 * dibatalkan (RULE-05); status penduduk yang keliru mengubah statistik,
 * laporan ke kecamatan, dan kelayakan bantuan. Keputusan sebesar itu harus
 * ditekan oleh orang, bukan oleh akibat samping penerbitan surat. Selain itu
 * kelahiran/kematian sering sudah dicatat lewat jalur lain lebih dulu —
 * mencatat otomatis akan menghasilkan mutasi ganda.
 *
 * MENGAPA PENOLAKAN TIDAK PERNAH MENGHALANGI PENERBITAN.
 * Tawaran dibuat SETELAH surat terbit dan tidak diperiksa di mana pun oleh
 * mesin status pengajuan. Warga yang kehilangan anggota keluarga tidak boleh
 * menunggu di loket karena petugas belum siap mengambil keputusan mutasi.
 */
final class MutationOfferService
{
    public function __construct(
        private readonly ResidentRepository $residents,
        private readonly ResidentService $residentService,
        private readonly MutationService $mutations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Buat tawaran bila jenis suratnya memang menawarkan mutasi.
     *
     * Mengembalikan null untuk jenis surat lain — pemanggil tidak perlu tahu
     * jenis surat mana saja yang relevan, sehingga daftarnya tetap di satu
     * tempat (LetterMutationOffer::OFFERED_BY_LETTER_CODE).
     */
    public function createFor(LetterRequest $request, Letter $letter): ?LetterMutationOffer
    {
        $type = LetterMutationOffer::OFFERED_BY_LETTER_CODE[$request->letterType->code] ?? null;

        if ($type === null) {
            return null;
        }

        $offer = new LetterMutationOffer;
        $offer->forceFill([
            'request_id' => $request->id,
            'letter_id' => $letter->id,
            'mutation_type' => $type,
            'status' => LetterMutationOffer::STATUS_PENDING,
        ])->save();

        return $offer;
    }

    /**
     * Terima tawaran kematian — satu klik, seluruh datanya dari formulir SKM.
     *
     * Tidak ada isian tambahan di sini: tanggal, tempat, dan sebab kematian
     * sudah diisi pemohon dan sudah TERCETAK di surat yang terbit. Meminta
     * petugas mengetiknya ulang membuka peluang mutasi yang berbeda dari
     * surat atas peristiwa yang sama.
     */
    public function acceptDeath(LetterMutationOffer $offer, LetterRequest $request): ResidentMutation
    {
        $this->guardAcceptable($offer, $request, ResidentMutation::TYPE_DEATH);

        $deceased = $this->deceasedOf($request);
        $form = $request->form_data;

        return DB::transaction(function () use ($offer, $request, $deceased, $form): ResidentMutation {
            $mutation = $this->mutations->recordDeath($deceased, [
                'mutation_date' => (string) ($form['tanggal_kematian'] ?? ''),
                'death_cause' => $this->text($form['sebab_kematian'] ?? null, 100),
                'death_place' => $this->text($form['tempat_kematian'] ?? null, 150),
                /*
                 * Nomor SURAT DESA, bukan surat keterangan rumah sakit:
                 * inilah dokumen yang menjadi dasar mutasi ini, dan nomornya
                 * yang dapat dicari kembali di arsip desa.
                 */
                'document_no' => $request->letter?->letter_number,
                'reported_by' => $this->reporterLabel($request),
                'reason' => 'Dicatat dari tawaran surat '.$request->letterType->code
                    .' '.$request->ticket_number,
                'letter_id' => $offer->letter_id,
            ]);

            $this->markAccepted($offer, $mutation);

            return $mutation;
        });
    }

    /**
     * Terima tawaran kelahiran: bayi masuk KK pemohon, lalu kelahirannya
     * dicatat sebagai mutasi.
     *
     * `$input` hanya memuat yang TIDAK ADA di formulir surat — terutama NIK
     * bayi. Lihat KEP-015: bayi baru lahir belum punya NIK, dan NIK itulah
     * yang justru diurus dengan surat ini, sehingga tawaran kelahiran tidak
     * dapat menjadi satu klik seperti kematian.
     *
     * @param  array<string, mixed>  $input
     */
    public function acceptBirth(
        LetterMutationOffer $offer,
        LetterRequest $request,
        array $input,
    ): ResidentMutation {
        $this->guardAcceptable($offer, $request, ResidentMutation::TYPE_BIRTH);

        $applicant = $request->resident;
        $familyId = $applicant->family_id;

        if ($familyId === null) {
            throw ValidationException::withMessages([
                'nik' => 'Pemohon belum terdaftar pada satu kartu keluarga, '
                    .'sehingga anaknya tidak dapat ditambahkan ke KK. Lengkapi '
                    .'data keluarga pemohon terlebih dahulu.',
            ]);
        }

        $form = $request->form_data;

        return DB::transaction(function () use ($offer, $request, $applicant, $familyId, $form, $input): ResidentMutation {
            $child = $this->residentService->create([
                'nik' => (string) $input['nik'],
                'family_id' => $familyId,
                /*
                 * Wilayah DIWARISI dari pemohon, tidak diisi petugas: bayi
                 * tinggal di rumah yang sama, dan wilayah inilah yang
                 * menentukan siapa saja dapat melihat datanya. Membiarkannya
                 * kosong akan membuat bayi hilang dari daftar Kepala Dusun
                 * yang justru mengurus keluarganya.
                 */
                'region_id' => $applicant->region_id,
                'full_name' => (string) $input['full_name'],
                'birth_place' => $this->text($form['tempat_lahir_anak'] ?? null, 100),
                'birth_date' => (string) ($form['tanggal_lahir_anak'] ?? ''),
                'gender' => self::genderCode((string) ($form['jenis_kelamin_anak'] ?? '')),
                'religion_code' => $applicant->religion_code,
                'marital_status_code' => 'belum_kawin',
                'family_relation_code' => 'anak',
                'citizenship' => 'WNI',
                'father_name' => $this->text($form['nama_ayah'] ?? null, 150),
                'mother_name' => $this->text($form['nama_ibu'] ?? null, 150),
                'address' => $applicant->address,
            ]);

            $mutation = $this->mutations->recordBirth($child, [
                'mutation_date' => (string) ($form['tanggal_lahir_anak'] ?? ''),
                'birth_weight' => $form['berat_badan'] ?? null,
                'birth_length' => $form['panjang_badan'] ?? null,
                'birth_helper' => $this->text($form['penolong_kelahiran'] ?? null, 50),
                'document_no' => $request->letter?->letter_number,
                'reported_by' => $this->reporterLabel($request),
                'reason' => 'Dicatat dari tawaran surat '.$request->letterType->code
                    .' '.$request->ticket_number,
                'letter_id' => $offer->letter_id,
            ]);

            $this->markAccepted($offer, $mutation);

            return $mutation;
        });
    }

    /**
     * Tolak tawaran — alasannya opsional, penolakannya permanen.
     *
     * Permanen karena satu-satunya keadaan yang ingin dihindari tawaran ini
     * adalah muncul berulang kali sampai diabaikan. Bila ternyata mutasinya
     * memang perlu dicatat, jalur biasa di modul kependudukan tetap terbuka
     * — dan di sanalah pencatatan manual seharusnya terjadi.
     */
    public function decline(LetterMutationOffer $offer, ?string $reason = null): LetterMutationOffer
    {
        if (! $offer->isPending()) {
            throw new ConflictHttpException(
                'Tawaran ini sudah '.$offer->status.'. Muat ulang halaman.'
            );
        }

        $actor = auth()->user();

        $offer->forceFill([
            'status' => LetterMutationOffer::STATUS_DECLINED,
            'decided_at' => now(),
            'decided_by' => $actor?->id,
            'decided_by_name' => $actor === null ? 'sistem' : $actor->name,
            'decline_reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
        ])->save();

        $this->audit->log('letter.mutation_offer_declined', $offer, context: [
            'jenis_mutasi' => $offer->mutation_type,
            'alasan' => $offer->decline_reason,
        ]);

        return $offer;
    }

    /**
     * Penduduk yang meninggal menurut formulir SKM.
     *
     * Diambil lewat ResidentRepository yang BERLINGKUP, bukan dari relasi
     * pengajuan: `resident_meninggal_id` adalah isian formulir, dan isian
     * formulir tidak pernah diperiksa ulang lingkupnya. Petugas yang tidak
     * berwenang atas warga itu mendapat 404 — bukan 403, yang justru
     * memastikan bahwa orangnya ada (docs/06 §4).
     */
    private function deceasedOf(LetterRequest $request): Resident
    {
        $id = $request->form_data['resident_meninggal_id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw ValidationException::withMessages([
                'resident_id' => 'Formulir surat ini tidak memuat penduduk yang meninggal, '
                    .'sehingga mutasi tidak dapat dicatat dari sini.',
            ]);
        }

        return $this->residents->findOrFail($id);
    }

    private function markAccepted(LetterMutationOffer $offer, ResidentMutation $mutation): void
    {
        $actor = auth()->user();

        $offer->forceFill([
            'status' => LetterMutationOffer::STATUS_ACCEPTED,
            'resident_mutation_id' => $mutation->id,
            'decided_at' => now(),
            'decided_by' => $actor?->id,
            'decided_by_name' => $actor === null ? 'sistem' : $actor->name,
        ])->save();

        $this->audit->log('letter.mutation_offer_accepted', $offer, context: [
            'jenis_mutasi' => $offer->mutation_type,
            'mutasi_id' => $mutation->id,
        ]);
    }

    /**
     * Surat diambil dari PENGAJUAN, bukan dari relasi `letter` milik tawaran:
     * pemanggil sudah memuat pengajuan beserta suratnya lewat repository
     * berlingkup, dan membaca relasi kedua di sini hanya akan menambah kueri
     * yang persis sama (dan melanggar larangan lazy loading saat pengujian).
     */
    private function guardAcceptable(
        LetterMutationOffer $offer,
        LetterRequest $request,
        string $expectedType,
    ): void {
        if ($offer->mutation_type !== $expectedType) {
            throw new ConflictHttpException(sprintf(
                'Tawaran pada pengajuan ini adalah mutasi %s, bukan %s.',
                $offer->mutationLabel(),
                ResidentMutation::typeLabels()[$expectedType] ?? $expectedType,
            ));
        }

        if (! $offer->isPending()) {
            throw new ConflictHttpException(
                'Tawaran ini sudah '.$offer->status.'. Muat ulang halaman.'
            );
        }

        /*
         * Surat yang DIBATALKAN tidak lagi menjadi dasar apa pun (RULE-05).
         * Tanpa pagar ini, surat yang dibatalkan karena datanya keliru masih
         * dapat dipakai mengubah status penduduk — persis kekeliruan yang
         * pembatalan itu berusaha membetulkan.
         */
        if ($request->letter?->status === Letter::STATUS_CANCELLED) {
            throw new ConflictHttpException(
                'Surat ini sudah dibatalkan, sehingga tidak dapat menjadi dasar mutasi. '
                .'Catat mutasinya lewat modul kependudukan bila memang perlu.'
            );
        }
    }

    /** "Siti Aminah (anak)" — pelapor beserta hubungannya, bila ada. */
    private function reporterLabel(LetterRequest $request): string
    {
        $relation = $request->form_data['hubungan_pelapor'] ?? null;
        $name = $request->resident->full_name;

        return is_string($relation) && trim($relation) !== ''
            ? mb_substr($name.' ('.trim($relation).')', 0, 150)
            : mb_substr($name, 0, 150);
    }

    /** Potong sesuai lebar kolom; isian dinamis tidak mengenal batas itu. */
    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $limit);
    }

    /**
     * "Laki-laki" → "L".
     *
     * Formulir SKL menyimpan labelnya (docs/13 §5.4) karena label itulah yang
     * tercetak di surat, sementara kolom `residents.gender` hanya menerima
     * L/P. Default "L" tidak dipakai: jenis kelamin yang salah pada akta
     * kelahiran adalah kekeliruan yang mahal diperbaiki, jadi label yang
     * tidak dikenali ditolak.
     */
    private static function genderCode(string $label): string
    {
        return match (mb_strtolower(trim($label))) {
            'laki-laki', 'l' => 'L',
            'perempuan', 'p' => 'P',
            default => throw ValidationException::withMessages([
                'nik' => 'Jenis kelamin anak pada formulir surat tidak dikenali ("'
                    .$label.'"). Perbaiki isian surat terlebih dahulu.',
            ]),
        };
    }
}
