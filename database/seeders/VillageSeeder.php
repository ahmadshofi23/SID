<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Official;
use App\Models\Region;
use App\Models\Setting;
use App\Models\VillageProfile;
use Illuminate\Database\Seeder;

/**
 * Identitas desa, wilayah, perangkat, dan pengaturan awal.
 *
 * Nilai di bawah adalah CONTOH. Saat pemasangan di desa nyata, data ini
 * diubah dari menu Pengaturan > Identitas Desa — bukan dengan mengubah kode.
 */
final class VillageSeeder extends Seeder
{
    public function run(): void
    {
        VillageProfile::query()->updateOrCreate(['id' => 1], [
            /*
             * BELUM DIVERIFIKASI: kode Kemendagri, kode pos, nomor telepon,
             * surel, dan domain di bawah adalah NILAI SEMENTARA. Nama desa,
             * kecamatan, kabupaten, dan provinsi sudah sesuai; lima kolom itu
             * harus diambil dari dokumen resmi desa dan diubah dari menu
             * Pengaturan > Identitas Desa sebelum go-live. Kode wilayah yang
             * salah ikut tercetak pada NOMOR SURAT (lihat seedSettings() di
             * bawah), jadi membiarkannya berarti seluruh deret nomor surat
             * desa salah sejak surat pertama.
             */
            'code' => '1210032002',
            'name' => 'Teluk Sentosa',
            'district' => 'Panai Hulu',
            'regency' => 'Labuhanbatu',
            'province' => 'Sumatera Utara',
            'postal_code' => '21464',
            'address' => 'Jl. Besar Teluk Sentosa No. 1',
            'phone' => '0624000000',
            'email' => 'pemerintah@teluksentosa.desa.id',
            'website' => 'https://teluksentosa.desa.id',
            'timezone' => 'Asia/Jakarta',
            'vision' => 'Terwujudnya Desa Teluk Sentosa yang mandiri, sejahtera, dan transparan.',
        ]);
        VillageProfile::forget();

        $this->seedRegions();
        $this->seedOfficials();
        $this->seedSettings();
    }

    /** 2 dusun x 2 RW x 3 RT = 12 RT. Cukup untuk menguji pembatasan wilayah. */
    private function seedRegions(): void
    {
        $structure = [
            ['code' => '001', 'name' => 'Dusun Krajan', 'head' => 'Sugianto'],
            ['code' => '002', 'name' => 'Dusun Sidomulyo', 'head' => 'Marwan'],
        ];

        foreach ($structure as $dusunIndex => $dusun) {
            $dusunModel = Region::query()->firstOrCreate(
                ['parent_id' => null, 'code' => $dusun['code']],
                [
                    'level' => 'dusun',
                    'name' => $dusun['name'],
                    'head_name' => $dusun['head'],
                    'sort_order' => $dusunIndex + 1,
                ]
            );

            for ($rw = 1; $rw <= 2; $rw++) {
                $rwModel = Region::query()->firstOrCreate(
                    ['parent_id' => $dusunModel->id, 'code' => sprintf('%03d', $rw)],
                    [
                        'level' => 'rw',
                        'name' => sprintf('RW %02d', $rw),
                        'sort_order' => $rw,
                    ]
                );

                for ($rt = 1; $rt <= 3; $rt++) {
                    Region::query()->firstOrCreate(
                        ['parent_id' => $rwModel->id, 'code' => sprintf('%03d', $rt)],
                        [
                            'level' => 'rt',
                            'name' => sprintf('RT %03d', $rt),
                            'sort_order' => $rt,
                        ]
                    );
                }
            }
        }
    }

    private function seedOfficials(): void
    {
        $officials = [
            ['Budi Santoso', 'Kepala Desa', 'kepala_desa', true, 1],
            ['Siti Rohmah', 'Sekretaris Desa', 'sekretaris_desa', true, 2],
            ['Ahmad Fauzi', 'Kasi Pemerintahan', 'kasi_pemerintahan', false, 3],
            ['Dewi Lestari', 'Kasi Kesejahteraan', 'kasi_kesejahteraan', false, 4],
            ['Rahmat Hidayat', 'Kasi Pelayanan', 'kasi_pelayanan', false, 5],
            ['Nur Aisyah', 'Kaur Keuangan', 'kaur_keuangan', false, 6],
            ['Joko Prasetyo', 'Kaur Perencanaan', 'kaur_perencanaan', false, 7],
        ];

        foreach ($officials as [$name, $position, $code, $canSign, $order]) {
            Official::query()->firstOrCreate(
                ['position_code' => $code],
                [
                    'name' => $name,
                    'position' => $position,
                    'can_sign_letter' => $canSign,
                    'is_active' => true,
                    'sort_order' => $order,
                ]
            );
        }
    }

    /**
     * Pengaturan awal. Pola nomor surat SENGAJA memakai nilai umum dan
     * WAJIB diverifikasi dari arsip surat asli desa sebelum go-live
     * (docs/13-katalog-surat.md §3) — mengubahnya setelah ada surat terbit
     * menghasilkan deret nomor yang tidak konsisten.
     */
    private function seedSettings(): void
    {
        $defaults = [
            'letter.number_pattern' => '{urut}/{kode}/{kode_desa}/{bulan_romawi}/{tahun}',
            'letter.counter_mode' => 'per_type',   // per_type | single
            'letter.verified_number_pattern' => false,  // ditandai true setelah dicek desa
            'service.default_sla_days' => 1,
            'complaint.triage_sla_days' => 2,
            'complaint.resolution_sla_days' => 14,
            'stats.refresh_interval_minutes' => 60,
            'portal.show_statistics' => true,
        ];

        foreach ($defaults as $key => $value) {
            if (Setting::query()->whereKey($key)->doesntExist()) {
                Setting::put($key, $value);
            }
        }
    }
}
