<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Data contoh untuk pengembangan & pengujian — TIDAK untuk produksi.
 *
 * Penting (docs/09-testing-qa.md §3): NIK di sini SINTETIS. Strukturnya valid
 * (16 digit) tetapi tidak merujuk orang nyata. DILARANG memakai data warga
 * asli di lingkungan mana pun selain produksi.
 *
 * Sengaja memuat kasus tepi yang sering merusak sistem di lapangan:
 *   - nama satu kata, nama dengan apostrof, nama bergelar
 *   - KK beranggota 1 orang
 *   - penduduk berusia lanjut & bayi
 *   - penduduk berstatus meninggal (harus keluar dari statistik, RULE-03)
 */
final class DemoDataSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'kata-sandi-demo-2026';

    public function run(): void
    {
        $rtKrajan = $this->rt('Dusun Krajan', 'RW 01', 'RT 001');
        $rtKrajan2 = $this->rt('Dusun Krajan', 'RW 01', 'RT 002');
        $rtSidomulyo = $this->rt('Dusun Sidomulyo', 'RW 01', 'RT 001');

        // --- Keluarga 1: Dusun Krajan RT 001 ---------------------------------
        $family1 = $this->family('3201010001000001', $rtKrajan, 'Jl. Melati No. 12');
        $head1 = $this->resident($family1, $rtKrajan, [
            'nik' => '3201011503850001',
            'full_name' => 'Ahmad Hidayat',
            'birth_place' => 'Sukamaju',
            'birth_date' => '1985-03-15',
            'gender' => 'L',
            'family_relation_code' => 'kepala_keluarga',
            'religion_code' => 'islam',
            'marital_status_code' => 'kawin_tercatat',
            'education_code' => 'slta',
            'occupation_code' => 'petani',
        ]);
        $this->resident($family1, $rtKrajan, [
            'nik' => '3201015703870002',
            'full_name' => "Siti Ma'rufah",   // apostrof — kasus tepi normalisasi
            'birth_date' => '1987-03-17',
            'gender' => 'P',
            'family_relation_code' => 'istri',
            'religion_code' => 'islam',
            'marital_status_code' => 'kawin_tercatat',
            'education_code' => 'sltp',
            'occupation_code' => 'mengurus_rumah_tangga',
        ]);
        $this->resident($family1, $rtKrajan, [
            'nik' => '3201010101100003',
            'full_name' => 'Rizki Ahmad Hidayat',
            'birth_date' => '2010-01-01',
            'gender' => 'L',
            'family_relation_code' => 'anak',
            'religion_code' => 'islam',
            'marital_status_code' => 'belum_kawin',
            'education_code' => 'tamat_sd',
            'occupation_code' => 'pelajar_mahasiswa',
        ]);

        // --- Keluarga 2: Dusun Krajan RT 002 (satu anggota) ------------------
        $family2 = $this->family('3201010001000002', $rtKrajan2, 'Jl. Kenanga No. 3');
        $this->resident($family2, $rtKrajan2, [
            'nik' => '3201011007400004',
            'full_name' => 'Suprapto',        // nama satu kata — kasus tepi
            'birth_date' => '1940-07-10',     // usia lanjut (> 85)
            'gender' => 'L',
            'family_relation_code' => 'kepala_keluarga',
            'religion_code' => 'islam',
            'marital_status_code' => 'cerai_mati',
            'education_code' => 'tamat_sd',
            'occupation_code' => 'pensiunan',
        ]);

        // --- Keluarga 3: Dusun Sidomulyo (untuk uji pembatasan wilayah) ------
        $family3 = $this->family('3201010001000003', $rtSidomulyo, 'Jl. Mawar No. 8');
        $this->resident($family3, $rtSidomulyo, [
            'nik' => '3201012012750005',
            'full_name' => 'Drs. Bambang Wijaya',   // bergelar — kasus tepi
            'birth_date' => '1975-12-20',
            'gender' => 'L',
            'family_relation_code' => 'kepala_keluarga',
            'religion_code' => 'kristen',
            'marital_status_code' => 'kawin_tercatat',
            'education_code' => 'd4_s1',
            'occupation_code' => 'guru',
        ]);
        $this->resident($family3, $rtSidomulyo, [
            'nik' => '3201015505500006',
            'full_name' => 'Kartini',
            'birth_date' => '1950-05-15',
            'gender' => 'P',
            'family_relation_code' => 'orang_tua',
            'religion_code' => 'kristen',
            'marital_status_code' => 'cerai_mati',
            'status' => 'meninggal',    // harus keluar dari statistik (RULE-03)
        ]);
        $this->resident($family3, $rtSidomulyo, [
            'nik' => '3201014101260007',
            'full_name' => 'Bayi Wijaya',
            'birth_date' => now()->subDays(10)->toDateString(),  // bayi
            'gender' => 'P',
            'family_relation_code' => 'anak',
            'religion_code' => 'kristen',
            'marital_status_code' => 'belum_kawin',
        ]);

        $this->seedUsers($head1, $rtKrajan);
    }

    private function seedUsers(Resident $citizenResident, Region $rtKrajan): void
    {
        $dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $dusunSidomulyo = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        $users = [
            ['admin@sukamaju.desa.id', 'Siti Rohmah (Sekdes)', Role::ADMIN_DESA, null, null],
            ['kades@sukamaju.desa.id', 'Budi Santoso', Role::KEPALA_DESA, null, null],
            ['kasi.pem@sukamaju.desa.id', 'Ahmad Fauzi', Role::KASI_PEMERINTAHAN, null, null],
            ['operator@sukamaju.desa.id', 'Rina Operator', Role::OPERATOR, null, null],
            ['kadus.krajan@sukamaju.desa.id', 'Sugianto (Kadus Krajan)', Role::KEPALA_DUSUN, $dusunKrajan->id, null],
            ['kadus.sido@sukamaju.desa.id', 'Marwan (Kadus Sidomulyo)', Role::KEPALA_DUSUN, $dusunSidomulyo->id, null],
            ['bpd@sukamaju.desa.id', 'Pengawas BPD', Role::PENGAWAS, null, null],
            ['warga@sukamaju.desa.id', 'Ahmad Hidayat', Role::WARGA, null, $citizenResident->id],
        ];

        foreach ($users as [$email, $name, $roleCode, $regionId, $residentId]) {
            $user = User::query()->firstOrNew(['email' => $email]);

            $user->fill(['name' => $name]);
            $user->password_hash = Hash::make(self::DEMO_PASSWORD);
            $user->forceFill([
                'status' => User::STATUS_ACTIVE,
                'resident_id' => $residentId,
                'email_verified_at' => now(),
            ])->save();

            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $user->roles()->syncWithoutDetaching([
                $role->id => ['region_id' => $regionId],
            ]);
        }
    }

    private function rt(string $dusun, string $rw, string $rt): Region
    {
        $dusunModel = Region::query()->where('name', $dusun)->firstOrFail();
        $rwModel = Region::query()
            ->where('parent_id', $dusunModel->id)->where('name', $rw)->firstOrFail();

        return Region::query()
            ->where('parent_id', $rwModel->id)->where('name', $rt)->firstOrFail();
    }

    private function family(string $kkNumber, Region $region, string $address): Family
    {
        $family = new Family;
        $family->setKkNumber($kkNumber);
        $family->forceFill([
            'region_id' => $region->id,
            'address' => $address,
            'rt' => $region->code,
            'welfare_status_code' => 'menengah',
            'house_ownership_code' => 'milik_sendiri',
        ])->save();

        return $family;
    }

    /** @param  array<string, mixed>  $attributes */
    private function resident(Family $family, Region $region, array $attributes): Resident
    {
        $nik = (string) $attributes['nik'];
        unset($attributes['nik']);

        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill(array_merge([
            'family_id' => $family->id,
            'region_id' => $region->id,
            'citizenship' => 'WNI',
            'address' => $family->address,
            'status' => Resident::STATUS_ACTIVE,
            'full_name_norm' => NameNormalizer::normalize((string) $attributes['full_name']),
        ], $attributes))->save();

        return $resident;
    }
}
