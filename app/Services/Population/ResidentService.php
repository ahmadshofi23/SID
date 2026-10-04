<?php

declare(strict_types=1);

namespace App\Services\Population;

use App\Models\Resident;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Support\Pii\Nik;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan bisnis penduduk — FR-PDK-01, 06, 07, 10.
 *
 * Seluruh penulisan ke tabel `residents` melewati kelas ini, sehingga:
 *   - aturan bisnis tidak dapat dilewati lewat jalur lain,
 *   - setiap perubahan otomatis tercatat di audit log,
 *   - penanganan NIK (enkripsi + hash + masking) hanya ada di satu tempat.
 */
final class ResidentService
{
    public function __construct(
        private readonly ResidentRepository $residents,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Resident
    {
        $nik = Nik::normalize((string) $data['nik']);

        $this->guardNikUnique($nik, null);
        $this->guardSingleFamilyHead($data, null);

        return DB::transaction(function () use ($data, $nik): Resident {
            $resident = new Resident;
            $resident->setNik($nik);
            $resident->fill($this->fillable($data));
            $resident->save();

            $this->audit->log('resident.created', $resident, context: [
                'nama' => $resident->full_name,
                'wilayah' => $resident->region_id,
            ]);

            return $resident;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Resident $resident, array $data): Resident
    {
        if (($data['nik'] ?? null) !== null && $data['nik'] !== '') {
            $nik = Nik::normalize((string) $data['nik']);

            if ($nik !== $resident->revealNik()) {
                $this->guardNikUnique($nik, $resident->id);
                $resident->setNik($nik);
            }
        }

        $this->guardSingleFamilyHead($data, $resident);

        return DB::transaction(function () use ($resident, $data): Resident {
            // Ditangkap SEBELUM save(): sesudahnya Eloquent sudah menyinkronkan
            // atribut asli ke nilai baru.
            $before = $resident->getOriginal();

            $resident->fill($this->fillable($data));
            $resident->save();

            // Riwayat perubahan per field (FR-PDK-07). Mengembalikan null bila
            // tidak ada yang benar-benar berubah — tidak mencatat "perubahan"
            // kosong yang akan mengotori riwayat.
            $this->audit->logModelChange('resident.updated', $resident, $before);

            return $resident;
        });
    }

    /**
     * Penghapusan hanya SOFT DELETE (RULE-08).
     *
     * Data penduduk punya nilai historis: surat yang pernah terbit merujuk
     * padanya, dan statistik tahun lalu harus tetap dapat dijelaskan.
     * Penghapusan permanen tidak disediakan di antarmuka sama sekali.
     */
    public function delete(Resident $resident, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan penghapusan wajib diisi.',
            ]);
        }

        DB::transaction(function () use ($resident, $reason): void {
            $this->audit->log('resident.deleted', $resident, context: [
                'nama' => $resident->full_name,
                'alasan' => $reason,
            ]);

            $resident->delete();
        });
    }

    public function restore(Resident $resident): void
    {
        /*
         * Memulihkan penduduk dapat menabrak RULE-01 bila NIK-nya sudah
         * dipakai penduduk lain selama ia terhapus. Diperiksa lebih dulu agar
         * pengguna mendapat pesan yang dapat dipahami, bukan galat basis data.
         */
        $nik = $resident->revealNik();

        if ($nik !== null && $this->residents->nikTakenByOther($nik, $resident->id)) {
            throw ValidationException::withMessages([
                'nik' => 'Penduduk ini tidak dapat dipulihkan karena NIK-nya sudah '
                    .'dipakai oleh penduduk lain. Perbaiki data penduduk tersebut '
                    .'terlebih dahulu.',
            ]);
        }

        DB::transaction(function () use ($resident): void {
            $resident->restore();
            $this->audit->log('resident.restored', $resident, context: [
                'nama' => $resident->full_name,
            ]);
        });
    }

    /**
     * Ungkap NIK penuh — RULE-07.
     *
     * Satu-satunya jalan membaca NIK apa adanya, dan SELALU tercatat di audit
     * log. Dipisah menjadi metode tersendiri agar tidak mungkin terjadi tanpa
     * sengaja: `$resident->revealNik()` saja tidak cukup, pemanggil harus
     * secara sadar lewat sini.
     */
    public function revealNik(Resident $resident): ?string
    {
        $this->audit->logPiiReveal($resident, 'nik');

        return $resident->revealNik();
    }

    /** RULE-01: NIK unik di antara penduduk yang belum dihapus. */
    private function guardNikUnique(string $nik, ?string $exceptId): void
    {
        if (! $this->residents->nikTakenByOther($nik, $exceptId)) {
            return;
        }

        /*
         * Pesan sengaja TIDAK menyebut nama pemilik NIK tersebut. Kepala Dusun
         * yang memasukkan NIK milik warga dusun lain tidak boleh mengetahui
         * identitasnya hanya dari pesan galat — itu akan melewati pembatasan
         * wilayah lewat pintu belakang.
         */
        throw ValidationException::withMessages([
            'nik' => 'NIK ini sudah terdaftar pada penduduk lain yang masih aktif. '
                .'Periksa kembali, atau hubungi Admin Desa bila Anda yakin NIK ini benar.',
        ]);
    }

    /**
     * RULE-02: tepat satu kepala keluarga per KK.
     *
     * Basis data juga menegakkannya (indeks unik pada kolom generated), tetapi
     * pemeriksaan di sini memberi pesan yang dapat dipahami petugas — bukan
     * galat "Duplicate entry" dari MySQL.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardSingleFamilyHead(array $data, ?Resident $current): void
    {
        $relation = $data['family_relation_code'] ?? $current?->family_relation_code;
        $familyId = $data['family_id'] ?? $current?->family_id;

        if ($relation !== 'kepala_keluarga' || $familyId === null) {
            return;
        }

        $existingHead = $this->residents->membersOfFamily((string) $familyId)
            ->first(fn (Resident $r) => $r->isHeadOfFamily() && $r->id !== $current?->id);

        if ($existingHead === null) {
            return;
        }

        throw ValidationException::withMessages([
            'family_relation_code' => sprintf(
                'Kartu keluarga ini sudah memiliki kepala keluarga: %s. '
                .'Ubah hubungan keluarga orang tersebut terlebih dahulu.',
                $existingHead->full_name
            ),
        ]);
    }

    /**
     * Field yang boleh diisi dari masukan pengguna.
     *
     * Daftar putih eksplisit — bukan mengandalkan $guarded di model saja.
     * Kolom turunan PII, kolom generated, dan status tidak pernah ada di sini.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillable(array $data): array
    {
        $allowed = [
            'family_id', 'region_id', 'full_name', 'birth_place', 'birth_date',
            'gender', 'blood_type_code', 'religion_code', 'marital_status_code',
            'family_relation_code', 'education_code', 'occupation_code',
            'citizenship', 'father_name', 'mother_name', 'address', 'phone',
            'email', 'domicile_address', 'disability_code', 'is_poor', 'notes',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
