<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tabel referensi — docs/05-model-data.md §3.7.
 *
 * Kode mengikuti praktik Dukcapil/BPS agar statistik dapat dibandingkan dan
 * ekspor ke Prodeskel/SIPD tidak perlu pemetaan ulang (prinsip Satu Data).
 */
final class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed('ref_religions', [
            'islam' => 'Islam',
            'kristen' => 'Kristen',
            'katolik' => 'Katolik',
            'hindu' => 'Hindu',
            'buddha' => 'Buddha',
            'konghucu' => 'Konghucu',
            'kepercayaan' => 'Kepercayaan Terhadap Tuhan YME',
        ]);

        $this->seed('ref_marital_statuses', [
            'belum_kawin' => 'Belum Kawin',
            'kawin_tercatat' => 'Kawin Tercatat',
            'kawin_belum_tercatat' => 'Kawin Belum Tercatat',
            'cerai_hidup' => 'Cerai Hidup',
            'cerai_mati' => 'Cerai Mati',
        ]);

        $this->seed('ref_family_relations', [
            'kepala_keluarga' => 'Kepala Keluarga',
            'suami' => 'Suami',
            'istri' => 'Istri',
            'anak' => 'Anak',
            'menantu' => 'Menantu',
            'cucu' => 'Cucu',
            'orang_tua' => 'Orang Tua',
            'mertua' => 'Mertua',
            'famili_lain' => 'Famili Lain',
            'pembantu' => 'Pembantu',
            'lainnya' => 'Lainnya',
        ]);

        $this->seed('ref_educations', [
            'tidak_belum_sekolah' => 'Tidak/Belum Sekolah',
            'belum_tamat_sd' => 'Belum Tamat SD/Sederajat',
            'tamat_sd' => 'Tamat SD/Sederajat',
            'sltp' => 'SLTP/Sederajat',
            'slta' => 'SLTA/Sederajat',
            'd1_d2' => 'Diploma I/II',
            'd3' => 'Akademi/Diploma III/S. Muda',
            'd4_s1' => 'Diploma IV/Strata I',
            's2' => 'Strata II',
            's3' => 'Strata III',
        ]);

        // Subset pekerjaan yang paling lazim di desa. Daftar penuh Dukcapil
        // (± 90 entri) ditambahkan pada Sprint 2 bersama modul kependudukan.
        $this->seed('ref_occupations', [
            'belum_tidak_bekerja' => 'Belum/Tidak Bekerja',
            'mengurus_rumah_tangga' => 'Mengurus Rumah Tangga',
            'pelajar_mahasiswa' => 'Pelajar/Mahasiswa',
            'pensiunan' => 'Pensiunan',
            'pns' => 'Pegawai Negeri Sipil',
            'tni' => 'Tentara Nasional Indonesia',
            'polri' => 'Kepolisian RI',
            'petani' => 'Petani/Pekebun',
            'peternak' => 'Peternak',
            'nelayan' => 'Nelayan/Perikanan',
            'buruh_tani' => 'Buruh Tani/Perkebunan',
            'buruh_harian' => 'Buruh Harian Lepas',
            'karyawan_swasta' => 'Karyawan Swasta',
            'karyawan_bumn' => 'Karyawan BUMN',
            'wiraswasta' => 'Wiraswasta',
            'pedagang' => 'Pedagang',
            'guru' => 'Guru',
            'bidan' => 'Bidan',
            'perawat' => 'Perawat',
            'sopir' => 'Sopir',
            'tukang_bangunan' => 'Tukang Bangunan',
            'perangkat_desa' => 'Perangkat Desa',
            'lainnya' => 'Pekerjaan Lainnya',
        ]);

        $this->seed('ref_blood_types', [
            'a' => 'A', 'b' => 'B', 'ab' => 'AB', 'o' => 'O',
            'a_plus' => 'A+', 'a_minus' => 'A-', 'b_plus' => 'B+', 'b_minus' => 'B-',
            'ab_plus' => 'AB+', 'ab_minus' => 'AB-', 'o_plus' => 'O+', 'o_minus' => 'O-',
            'tidak_tahu' => 'Tidak Tahu',
        ]);

        $this->seed('ref_disabilities', [
            'fisik' => 'Disabilitas Fisik',
            'netra' => 'Disabilitas Netra/Buta',
            'rungu_wicara' => 'Disabilitas Rungu/Wicara',
            'mental' => 'Disabilitas Mental/Jiwa',
            'fisik_mental' => 'Disabilitas Fisik dan Mental',
            'lainnya' => 'Disabilitas Lainnya',
        ]);

        $this->seed('ref_welfare_statuses', [
            'sangat_miskin' => 'Sangat Miskin',
            'miskin' => 'Miskin',
            'rentan_miskin' => 'Rentan Miskin',
            'menengah' => 'Menengah',
            'sejahtera' => 'Sejahtera',
        ]);

        $this->seed('ref_house_ownerships', [
            'milik_sendiri' => 'Milik Sendiri',
            'sewa_kontrak' => 'Sewa/Kontrak',
            'menumpang' => 'Menumpang',
            'rumah_dinas' => 'Rumah Dinas',
            'lainnya' => 'Lainnya',
        ]);

        $this->seed('ref_complaint_categories', [
            'infrastruktur' => 'Infrastruktur & Jalan',
            'pelayanan' => 'Pelayanan Administrasi',
            'bantuan_sosial' => 'Bantuan Sosial',
            'lingkungan' => 'Lingkungan & Kebersihan',
            'keamanan' => 'Keamanan & Ketertiban',
            'kesehatan' => 'Kesehatan',
            'pendidikan' => 'Pendidikan',
            'lainnya' => 'Lainnya',
        ]);

        $this->seed('ref_post_categories', [
            'pembangunan' => 'Pembangunan',
            'kesehatan' => 'Kesehatan',
            'pendidikan' => 'Pendidikan',
            'ekonomi' => 'Ekonomi & UMKM',
            'sosial' => 'Sosial & Budaya',
            'pemerintahan' => 'Pemerintahan',
        ]);

        $this->seed('ref_asset_categories', [
            'tanah' => 'Tanah',
            'bangunan' => 'Gedung & Bangunan',
            'peralatan_mesin' => 'Peralatan & Mesin',
            'jalan_jaringan' => 'Jalan, Irigasi & Jaringan',
            'aset_lainnya' => 'Aset Tetap Lainnya',
        ]);
    }

    /** @param  array<string, string>  $items */
    private function seed(string $table, array $items): void
    {
        $order = 0;

        foreach ($items as $code => $name) {
            DB::table($table)->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'sort_order' => ++$order, 'is_active' => true]
            );
        }
    }
}
