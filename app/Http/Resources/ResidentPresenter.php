<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Support\ReferenceData;

/**
 * Menyiapkan data penduduk untuk dikirim ke peramban — KEP-008.
 *
 * ATURAN PALING PENTING DI BERKAS INI: nilai yang dikirim ke klien harus sudah
 * siap tampil dan sudah ter-masking. NIK apa adanya TIDAK PERNAH lewat sini;
 * ia hanya dapat diperoleh lewat endpoint tersendiri yang memerlukan izin
 * khusus dan tercatat di audit log (RULE-07).
 *
 * Kode referensi juga diterjemahkan di sisi server. Mengirim kode mentah
 * (`islam`, `kawin_tercatat`) lalu menerjemahkannya di Vue berarti memelihara
 * dua daftar label — dan keduanya pasti akan berbeda suatu saat.
 */
final class ResidentPresenter
{
    /**
     * Bentuk ringkas untuk baris daftar.
     *
     * @return array<string, mixed>
     */
    public static function row(Resident $resident): array
    {
        $region = $resident->region;

        return [
            'id' => $resident->id,
            'full_name' => $resident->full_name,
            'nik_masked' => $resident->nik_masked,
            'gender' => $resident->gender,
            'gender_label' => self::genderLabel($resident->gender),
            'age' => $resident->age(),
            'status' => $resident->status,
            'status_label' => self::statusLabel($resident->status),
            'region' => $region instanceof Region ? $region->shortPath() : null,
        ];
    }

    /**
     * Bentuk lengkap untuk halaman detail.
     *
     * @return array<string, mixed>
     */
    public static function detail(Resident $resident): array
    {
        $references = ReferenceData::labels();
        $region = $resident->region;

        return [
            ...self::row($resident),

            'birth_place' => $resident->birth_place,
            'birth_date' => $resident->birth_date->format('Y-m-d'),
            'birth_date_label' => $resident->birth_date
                ->locale('id')->translatedFormat('d F Y'),
            'region_full' => $region instanceof Region ? $region->displayPath() : null,
            'region_id' => $resident->region_id,
            'address' => $resident->address,
            'citizenship' => $resident->citizenship,
            'father_name' => $resident->father_name,
            'mother_name' => $resident->mother_name,
            'phone' => $resident->phone,
            'email' => $resident->email,
            'notes' => $resident->notes,
            'is_poor' => $resident->is_poor,

            // Kode mentah untuk formulir, label siap tampil untuk halaman detail.
            'religion_code' => $resident->religion_code,
            'religion' => $references['religions'][$resident->religion_code] ?? null,
            'marital_status_code' => $resident->marital_status_code,
            'marital_status' => $references['marital_statuses'][$resident->marital_status_code] ?? null,
            'education_code' => $resident->education_code,
            'education' => $references['educations'][$resident->education_code] ?? null,
            'occupation_code' => $resident->occupation_code,
            'occupation' => $references['occupations'][$resident->occupation_code] ?? null,
            'blood_type_code' => $resident->blood_type_code,
            'blood_type' => $references['blood_types'][$resident->blood_type_code] ?? null,
            'disability_code' => $resident->disability_code,
            'disability' => $references['disabilities'][$resident->disability_code] ?? null,
            'family_relation_code' => $resident->family_relation_code,
            'family_relation' => $references['family_relations'][$resident->family_relation_code] ?? null,
            'family_id' => $resident->family_id,
        ];
    }

    /**
     * Baris anggota keluarga.
     *
     * @return array<string, mixed>
     */
    public static function member(Resident $resident): array
    {
        $references = ReferenceData::get('family_relations');

        return [
            'id' => $resident->id,
            'full_name' => $resident->full_name,
            'relation' => $references[$resident->family_relation_code] ?? '—',
            'age' => $resident->age(),
            'gender_label' => self::genderLabel($resident->gender),
            'status' => $resident->status,
            'status_label' => self::statusLabel($resident->status),
            'is_head' => $resident->isHeadOfFamily(),
        ];
    }

    /** @return array<string, mixed> */
    public static function family(Family $family): array
    {
        $region = $family->region;

        return [
            'id' => $family->id,
            'kk_number_masked' => $family->kk_number_masked,
            'address' => $family->address,
            'rt' => $family->rt,
            'rw' => $family->rw,
            'region' => $region instanceof Region ? $region->shortPath() : null,
            'region_full' => $region instanceof Region ? $region->displayPath() : null,
            'region_id' => $family->region_id,
            'members_count' => $family->members_count ?? null,
            'welfare_status_code' => $family->welfare_status_code,
            'welfare_status' => ReferenceData::get('welfare_statuses')[$family->welfare_status_code] ?? null,
            'house_ownership_code' => $family->house_ownership_code,
            'house_ownership' => ReferenceData::get('house_ownerships')[$family->house_ownership_code] ?? null,
        ];
    }

    public static function genderLabel(string $gender): string
    {
        return $gender === 'L' ? 'Laki-laki' : 'Perempuan';
    }

    public static function statusLabel(string $status): string
    {
        return [
            'aktif' => 'Aktif',
            'meninggal' => 'Meninggal',
            'pindah_keluar' => 'Pindah keluar',
            'hilang' => 'Hilang',
            'tidak_diketahui' => 'Tidak diketahui',
        ][$status] ?? ucfirst($status);
    }
}
