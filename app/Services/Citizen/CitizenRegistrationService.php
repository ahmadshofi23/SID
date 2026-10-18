<?php

declare(strict_types=1);

namespace App\Services\Citizen;

use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Upload\FileSignature;
use App\Support\NameNormalizer;
use App\Support\Pii\Nik;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Registrasi & verifikasi akun warga — FR-AUT-08, FR-AUT-09, FR-AUT-10, UC-15.
 *
 * SATU ATURAN MENGIKAT SELURUH KELAS INI: pemanggil TIDAK PERNAH diberi tahu
 * mengapa sebuah pendaftaran tidak menghasilkan akun. Metode register()
 * karena itu tidak mengembalikan apa pun dan tidak melempar pengecualian untuk
 * data yang tidak cocok — satu-satunya pengecualian adalah berkas KTP yang
 * ditolak, karena itu kesalahan yang dapat diperbaiki pemohon sendiri tanpa
 * membocorkan apa pun tentang warga lain.
 *
 * Alasannya ada di FR-AUT-10 dan KEP-017: formulir pendaftaran yang menjawab
 * "NIK tidak terdaftar" berbeda dari "NIK sudah punya akun" adalah alat untuk
 * memeriksa apakah seseorang warga desa ini — dan, dengan mencoba NIK
 * berurutan, untuk menyusun daftar penduduk desa tanpa pernah masuk sistem.
 */
final class CitizenRegistrationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    private const KTP_DISK = 'local';

    private const KTP_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Daftarkan warga. SELALU "berhasil" dari sudut pandang pemanggil.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(
        array $data,
        ResidentRepository $residents,
        FamilyRepository $families,
        ?UploadedFile $ktp = null,
    ): void {
        $resident = $this->matchResident($data, $residents, $families);

        if ($resident === null) {
            /*
             * Tidak cocok: tidak ada akun yang dibuat, dan pemohon tetap
             * melihat halaman yang sama. Dicatat ke audit log dengan NIK
             * TER-HASH — petugas dapat melihat ada berapa banyak percobaan
             * yang gagal (dan mengenali pola percobaan berurutan) tanpa ada
             * satu pun NIK tersimpan dalam bentuk terbaca.
             */
            $this->audit->log('citizen.registration_unmatched', context: [
                'nik_hash' => $this->hashedNik($data['nik'] ?? ''),
            ]);

            return;
        }

        if ($this->contactTaken($data) || $this->residentHasAccount($resident)) {
            /*
             * Kontak sudah dipakai, atau warga ini sudah punya akun. Jawaban
             * tetap sama persis — pesan "email sudah terdaftar" akan
             * mengubah formulir ini menjadi alat memeriksa keanggotaan.
             * Halaman konfirmasi karena itu MENYEBUTKAN jalan keluarnya
             * ("bila Anda merasa sudah pernah mendaftar, pakai Lupa kata
             * sandi"), sehingga warga yang sah tidak kehilangan arah.
             */
            $this->audit->log('citizen.registration_duplicate', $resident, context: [
                'alasan' => $this->contactTaken($data) ? 'kontak_terpakai' : 'penduduk_sudah_punya_akun',
            ]);

            return;
        }

        DB::transaction(function () use ($data, $resident, $ktp): void {
            $user = new User;
            $user->forceFill([
                'name' => $resident->full_name,
                'email' => $this->blankToNull($data['email'] ?? null),
                'phone' => $this->blankToNull($data['phone'] ?? null),
                'password_hash' => Hash::make((string) $data['password']),
                'resident_id' => $resident->id,
                // Belum `aktif`: LoginController menolak setiap akun yang
                // bukan aktif, jadi akun ini belum dapat dipakai masuk.
                'status' => User::STATUS_PENDING,
                'verification_method' => $data['verification_method'],
                'verification_document_path' => $ktp === null ? null : $this->storeKtp($ktp),
            ])->save();

            $role = Role::query()->where('code', Role::WARGA)->firstOrFail();
            $user->roles()->attach($role->id);

            $this->audit->log('citizen.registered', $user, context: [
                'metode_verifikasi' => $user->verification_method,
            ]);
        });
    }

    /**
     * Cocokkan NIK + nomor KK + nama ke satu penduduk AKTIF.
     *
     * KETIGANYA HARUS COCOK. NIK saja tidak cukup: NIK beredar di fotokopi
     * KTP yang ditinggalkan di mana-mana, dan nomor KK beserta nama adalah
     * dua hal yang hanya diketahui orang di dalam keluarga itu. Penduduk
     * berstatus meninggal atau pindah ditolak (RULE-03) — akun atas nama
     * orang yang sudah tidak berdomisili di desa ini tidak boleh ada.
     *
     * @param  array<string, mixed>  $data
     */
    private function matchResident(
        array $data,
        ResidentRepository $residents,
        FamilyRepository $families,
    ): ?Resident {
        $nik = Nik::normalize((string) ($data['nik'] ?? ''));
        $kk = Nik::normalize((string) ($data['kk_number'] ?? ''));

        if (! Nik::isWellFormed($nik) || ! Nik::isWellFormed($kk)) {
            return null;
        }

        /*
         * Pencocokan MENGABAIKAN lingkup akses, dan memang harus: pemohon
         * adalah tamu tanpa AccessScope sama sekali, sehingga kueri
         * berlingkup akan mengembalikan nol baris untuk setiap warga.
         * Yang menjaga keamanannya bukan lingkup melainkan tiga syarat yang
         * harus cocok sekaligus, ditambah pembatasan laju pada rutenya.
         */
        $resident = $residents->findByNikIgnoringScope($nik);

        if ($resident === null || $resident->status !== Resident::STATUS_ACTIVE) {
            return null;
        }

        $family = $families->findByKkNumberIgnoringScope($kk);

        if ($family === null || $family->id !== $resident->family_id) {
            return null;
        }

        /*
         * Nama dibandingkan dalam bentuk ternormalisasi: "H. Siti Rohmah" dan
         * "siti  rohmah" adalah orang yang sama, dan menolak warga karena
         * gelar atau spasi ganda hanya memindahkan pekerjaan ke loket desa.
         */
        $nama = NameNormalizer::normalize((string) ($data['full_name'] ?? ''));

        return $nama !== '' && $nama === $resident->full_name_norm ? $resident : null;
    }

    /** @param array<string, mixed> $data */
    private function contactTaken(array $data): bool
    {
        $email = $this->blankToNull($data['email'] ?? null);
        $phone = $this->blankToNull($data['phone'] ?? null);

        return User::query()
            ->where(function ($q) use ($email, $phone): void {
                $q->when($email !== null, fn ($i) => $i->orWhere('email', $email))
                    ->when($phone !== null, fn ($i) => $i->orWhere('phone', $phone));
            })
            ->exists();
    }

    private function residentHasAccount(Resident $resident): bool
    {
        return User::query()->where('resident_id', $resident->id)->exists();
    }

    /* ========================== Verifikasi petugas ======================== */

    /**
     * Setujui akun warga — akun menjadi dapat dipakai masuk.
     */
    public function approve(User $user, User $officer): void
    {
        DB::transaction(function () use ($user, $officer): void {
            $ktp = $user->verification_document_path;

            $user->forceFill([
                'status' => User::STATUS_ACTIVE,
                'verified_by' => $officer->id,
                'verified_at' => now(),
                'verification_note' => null,
                /*
                 * Foto KTP DIHAPUS begitu verifikasi selesai.
                 *
                 * Ia hanya diperlukan untuk satu keputusan, dan keputusan itu
                 * sudah diambil. Menyimpannya "untuk jaga-jaga" berarti desa
                 * memelihara arsip foto KTP seluruh warganya — sasaran yang
                 * jauh lebih berharga bagi penyerang daripada basis datanya
                 * sendiri, dan tidak diminta satu pun kebutuhan (docs/08 §3,
                 * minimalisasi data).
                 */
                'verification_document_path' => null,
            ])->save();

            if ($ktp !== null) {
                Storage::disk(self::KTP_DISK)->delete($ktp);
            }

            $this->audit->log('citizen.verified', $user, context: [
                'metode' => $user->verification_method,
            ], actor: $officer);
        });
    }

    /** Tolak pendaftaran, dengan alasan yang dapat dibacakan ke warga. */
    public function reject(User $user, User $officer, string $reason): void
    {
        DB::transaction(function () use ($user, $officer, $reason): void {
            $ktp = $user->verification_document_path;

            $user->forceFill([
                'status' => User::STATUS_DISABLED,
                'verified_by' => $officer->id,
                'verified_at' => now(),
                'verification_note' => $reason,
                'verification_document_path' => null,
            ])->save();

            if ($ktp !== null) {
                Storage::disk(self::KTP_DISK)->delete($ktp);
            }

            $this->audit->log('citizen.verification_rejected', $user, context: [
                'alasan' => $reason,
            ], actor: $officer);
        });
    }

    /* ============================== Berkas KTP ============================ */

    /** Alasan penolakan berkas KTP, atau null bila diterima. */
    public function ktpRejectionReason(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return 'Foto KTP gagal diunggah seluruhnya ('.$file->getErrorMessage().'). Coba lagi.';
        }

        if ($file->getSize() > self::KTP_MAX_BYTES) {
            return 'Ukuran berkas melebihi 5 MB. Foto dari kamera ponsel biasanya '
                .'sudah cukup tanpa perlu diperbesar.';
        }

        $signature = FileSignature::detect((string) $file->getRealPath());

        if ($signature === null || ! in_array($signature->extension, ['jpg', 'png', 'pdf'], true)) {
            return 'Isi berkas bukan foto JPG/PNG atau PDF. Mengubah nama berkas '
                .'tidak mengubah isinya — unggah hasil fotonya langsung.';
        }

        return null;
    }

    private function storeKtp(UploadedFile $file): string
    {
        $signature = FileSignature::detect((string) $file->getRealPath());

        return (string) $file->storeAs(
            'verifikasi-warga',
            Str::uuid7()->toString().'.'.($signature === null ? 'bin' : $signature->extension),
            self::KTP_DISK,
        );
    }

    /* ================================ Bantu =============================== */

    private function hashedNik(mixed $nik): string
    {
        $normal = Nik::normalize(is_string($nik) ? $nik : '');

        return $normal === '' ? '-' : substr(hash('sha256', $normal), 0, 16);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
