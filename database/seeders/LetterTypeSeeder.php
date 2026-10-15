<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LetterType;
use Illuminate\Database\Seeder;

/**
 * Katalog 22 jenis surat — docs/13-katalog-surat.md.
 *
 * Delapan jenis aktif saat pemasangan; empat belas sisanya tersedia dan
 * diaktifkan petugas dari menu Jenis Surat bila desa memerlukannya.
 *
 * TIGA HAL YANG WAJIB DISESUAIKAN DESA SEBELUM LAYANAN DIBUKA (docs/13 §8):
 *
 *   1. `classification_code` SENGAJA dibiarkan null. Kode klasifikasi arsip
 *      berbeda antar kabupaten dan antar versi peraturan kearsipan; menebaknya
 *      berarti kode salah terbawa ke seluruh surat selama bertahun-tahun.
 *      Ambil dari arsip surat desa yang sudah ada.
 *   2. Pola penomoran harus dibandingkan dengan surat asli desa.
 *   3. Redaksi template harus dibandingkan dengan surat asli desa, lalu satu
 *      surat uji per jenis dicetak dan diperiksa Sekretaris Desa.
 *
 * Seeder memakai updateOrCreate pada `code` sehingga aman dijalankan ulang,
 * TETAPI hanya memperbarui jenis yang belum disentuh petugas — lihat
 * keterangan di run().
 */
final class LetterTypeSeeder extends Seeder
{
    /** Alur satu tahap: operator buat → Kades setujui (docs/13 §4). */
    private const F1 = [
        ['step' => 1, 'action' => 'approve', 'role' => 'kepala_desa'],
    ];

    /** Alur tiga tahap untuk surat berisiko. */
    private const F3_PEMERINTAHAN = [
        ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pemerintahan'],
        ['step' => 2, 'action' => 'review', 'role' => 'sekretaris_desa'],
        ['step' => 3, 'action' => 'approve', 'role' => 'kepala_desa'],
    ];

    private const F3_KESEJAHTERAAN = [
        ['step' => 1, 'action' => 'verify', 'role' => 'kasi_kesejahteraan'],
        ['step' => 2, 'action' => 'review', 'role' => 'sekretaris_desa'],
        ['step' => 3, 'action' => 'approve', 'role' => 'kepala_desa'],
    ];

    private const F3_PELAYANAN = [
        ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pelayanan'],
        ['step' => 2, 'action' => 'review', 'role' => 'sekretaris_desa'],
        ['step' => 3, 'action' => 'approve', 'role' => 'kepala_desa'],
    ];

    /** Dokumen dasar yang diminta hampir semua surat. */
    private const SYARAT_DASAR = [
        ['label' => 'Fotokopi Kartu Keluarga', 'type' => 'file', 'required' => true],
        ['label' => 'Fotokopi KTP pemohon', 'type' => 'file', 'required' => true],
        ['label' => 'Surat pengantar RT/RW', 'type' => 'file', 'required' => true],
    ];

    public function run(): void
    {
        foreach ($this->catalog() as $order => $type) {
            $existing = LetterType::withTrashed()->where('code', $type['code'])->first();

            /*
             * Jenis surat yang sudah pernah DISUNTING petugas tidak ditimpa.
             *
             * Seeder dijalankan ulang setiap `migrate:fresh --seed` di
             * pengembangan, tetapi di desa yang sudah berjalan ia juga
             * dipanggil saat pembaruan versi. Menimpa template yang sudah
             * disesuaikan dengan redaksi surat asli desa akan membatalkan
             * pekerjaan Sekretaris Desa tanpa pemberitahuan.
             */
            if ($existing !== null && $existing->updated_by !== null) {
                continue;
            }

            LetterType::withTrashed()->updateOrCreate(
                ['code' => $type['code']],
                [
                    ...$type,
                    'classification_code' => null,
                    'number_pattern' => '{urut}/{kode}/{kode_desa}/{bulan_romawi}/{tahun}',
                    'fee' => 0,
                    'sort_order' => $order + 1,
                    'deleted_at' => null,
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalog(): array
    {
        return [
            $this->sktm(),
            $this->skd(),
            $this->sku(),
            $this->skl(),
            $this->skm(),
            $this->skp(),
            $this->skbm(),
            $this->spskck(),
            ...$this->inactiveTypes(),
        ];
    }

    /* ======================= Delapan surat aktif ======================= */

    /** @return array<string, mixed> */
    private function sktm(): array
    {
        return [
            'code' => 'SKTM',
            'name' => 'Surat Keterangan Tidak Mampu',
            'title_on_letter' => 'SURAT KETERANGAN TIDAK MAMPU',
            'description' => 'Keterangan bahwa warga termasuk keluarga tidak mampu secara '
                .'ekonomi, untuk keperluan beasiswa, keringanan biaya sekolah/rumah sakit, '
                .'atau pengajuan bantuan.',
            'sla_days' => 1,
            'validity_months' => 3,
            'is_public' => true,
            'is_active' => true,
            'approval_flow' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_kesejahteraan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            'requirements' => [
                ...self::SYARAT_DASAR,
                ['label' => 'Dokumen pendukung keperluan (surat sekolah/rumah sakit)',
                    'type' => 'file', 'required' => false],
            ],
            /*
             * Catatan kebijakan (docs/13 §5.1): SKTM TIDAK diterbitkan
             * otomatis dari kolom `is_poor`. Status ekonomi berubah, dan
             * penerbitan otomatis membuka celah penyalahgunaan. Karena itu
             * tidak ada field yang mengambil nilai itu di sini.
             */
            'form_schema' => ['fields' => [
                ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'select',
                    'required' => true, 'allow_other' => true, 'options' => [
                        'Pengajuan beasiswa', 'Keringanan biaya sekolah',
                        'Keringanan biaya rumah sakit', 'Pengajuan bantuan sosial', 'Lainnya',
                    ]],
                ['name' => 'keterangan_tambahan', 'label' => 'Keterangan tambahan',
                    'type' => 'textarea', 'required' => false, 'max' => 500,
                    'hint' => 'Contoh: untuk anak bernama ... kelas ... di SMAN ...'],
                ['name' => 'penghasilan_per_bulan', 'label' => 'Penghasilan per bulan',
                    'type' => 'currency', 'required' => false],
            ]],
            'template_html' => <<<'HTML'
                <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}}, Kecamatan
                {{kecamatan}}, Kabupaten {{kabupaten}}, dan menurut sepengetahuan kami
                yang bersangkutan termasuk <strong>keluarga yang tidak mampu</strong>
                secara ekonomi.</p>

                <p>Surat keterangan ini dibuat untuk keperluan:
                <strong>{{#upper}}{{form.keperluan}}{{/upper}}</strong>
                {{#form.keterangan_tambahan}} ({{form.keterangan_tambahan}}){{/form.keterangan_tambahan}}.</p>
                HTML,
        ];
    }

    /** @return array<string, mixed> */
    private function skd(): array
    {
        return [
            'code' => 'SKD',
            'name' => 'Surat Keterangan Domisili',
            'title_on_letter' => 'SURAT KETERANGAN DOMISILI',
            'description' => 'Keterangan tempat tinggal/domisili warga di desa ini.',
            'sla_days' => 1,
            'validity_months' => 6,
            'is_public' => true,
            'is_active' => true,
            'approval_flow' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pemerintahan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            'requirements' => self::SYARAT_DASAR,
            'form_schema' => ['fields' => [
                ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'text', 'required' => true],
                ['name' => 'alamat_domisili', 'label' => 'Alamat domisili',
                    'type' => 'text', 'required' => false,
                    'hint' => 'Isi hanya bila berbeda dari alamat pada Kartu Keluarga'],
                ['name' => 'lama_tinggal', 'label' => 'Lama tinggal',
                    'type' => 'text', 'required' => false, 'hint' => 'Contoh: sejak tahun 2015'],
            ]],
            'template_html' => <<<'HTML'
                <p>Orang tersebut di atas adalah benar penduduk yang berdomisili /
                bertempat tinggal di
                <strong>{{#form.alamat_domisili}}{{form.alamat_domisili}}{{/form.alamat_domisili}}{{^form.alamat_domisili}}{{alamat_lengkap}}{{/form.alamat_domisili}}</strong>,
                Desa {{desa}}, Kecamatan {{kecamatan}}, Kabupaten {{kabupaten}}{{#form.lama_tinggal}}
                {{form.lama_tinggal}}{{/form.lama_tinggal}}.</p>

                <p>Surat keterangan ini dibuat untuk keperluan
                <strong>{{#upper}}{{form.keperluan}}{{/upper}}</strong>.</p>
                HTML,
        ];
    }

    /** @return array<string, mixed> */
    private function sku(): array
    {
        return [
            'code' => 'SKU',
            'name' => 'Surat Keterangan Usaha',
            'title_on_letter' => 'SURAT KETERANGAN USAHA',
            'description' => 'Keterangan bahwa warga memiliki dan menjalankan usaha, '
                .'untuk pengajuan kredit, perizinan usaha, atau NIB/IUMK.',
            'sla_days' => 2,
            'validity_months' => 12,
            'is_public' => true,
            'is_active' => true,
            'approval_flow' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pelayanan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            'requirements' => [
                ...self::SYARAT_DASAR,
                ['label' => 'Foto tempat usaha', 'type' => 'file', 'required' => false],
            ],
            'form_schema' => ['fields' => [
                ['name' => 'nama_usaha', 'label' => 'Nama usaha', 'type' => 'text', 'required' => true],
                ['name' => 'jenis_usaha', 'label' => 'Jenis usaha', 'type' => 'text', 'required' => true],
                ['name' => 'alamat_usaha', 'label' => 'Alamat usaha', 'type' => 'text', 'required' => true],
                ['name' => 'tahun_mulai_usaha', 'label' => 'Tahun mulai usaha',
                    'type' => 'number', 'required' => true, 'min' => 1900, 'max' => 2100],
                ['name' => 'jumlah_pekerja', 'label' => 'Jumlah pekerja',
                    'type' => 'number', 'required' => false, 'min' => 0],
                ['name' => 'penghasilan_per_bulan', 'label' => 'Penghasilan per bulan',
                    'type' => 'currency', 'required' => false],
                ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'select',
                    'required' => true, 'allow_other' => true, 'options' => [
                        'Pengajuan kredit bank', 'Perizinan usaha', 'Pengurusan NIB/IUMK', 'Lainnya',
                    ]],
            ]],
            'template_html' => <<<'HTML'
                <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan menurut
                sepengetahuan kami yang bersangkutan memiliki usaha sebagai berikut:</p>

                <table>
                  <tr><td>Nama Usaha</td><td>: {{form.nama_usaha}}</td></tr>
                  <tr><td>Jenis Usaha</td><td>: {{form.jenis_usaha}}</td></tr>
                  <tr><td>Alamat Usaha</td><td>: {{form.alamat_usaha}}</td></tr>
                  <tr><td>Mulai Berusaha</td><td>: Tahun {{form.tahun_mulai_usaha}}</td></tr>
                </table>

                <p>Usaha tersebut benar adanya dan sampai saat surat ini diterbitkan
                masih berjalan.</p>

                <p>Surat keterangan ini dibuat untuk keperluan
                <strong>{{#upper}}{{form.keperluan}}{{/upper}}</strong>.</p>
                HTML,
        ];
    }

    /** @return array<string, mixed> */
    private function skl(): array
    {
        return [
            'code' => 'SKL',
            'name' => 'Surat Keterangan Kelahiran',
            'title_on_letter' => 'SURAT KETERANGAN KELAHIRAN',
            'description' => 'Keterangan kelahiran anak untuk pengurusan Akta Kelahiran '
                .'di Dinas Kependudukan dan Pencatatan Sipil.',
            'sla_days' => 1,
            'validity_months' => null,
            'is_public' => true,
            'is_active' => true,
            'approval_flow' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pemerintahan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            'requirements' => [
                ['label' => 'Surat keterangan lahir dari bidan/rumah sakit', 'type' => 'file', 'required' => true],
                ['label' => 'Fotokopi Kartu Keluarga', 'type' => 'file', 'required' => true],
                ['label' => 'Fotokopi KTP ayah dan ibu', 'type' => 'file', 'required' => true],
                ['label' => 'Fotokopi buku nikah / akta perkawinan', 'type' => 'file', 'required' => true],
                ['label' => 'Surat pengantar RT/RW', 'type' => 'file', 'required' => true],
            ],
            'form_schema' => ['fields' => [
                ['name' => 'nama_anak', 'label' => 'Nama anak', 'type' => 'text', 'required' => true],
                ['name' => 'jenis_kelamin_anak', 'label' => 'Jenis kelamin', 'type' => 'select',
                    'required' => true, 'options' => ['Laki-laki', 'Perempuan']],
                ['name' => 'tempat_lahir_anak', 'label' => 'Tempat lahir', 'type' => 'text', 'required' => true],
                ['name' => 'tanggal_lahir_anak', 'label' => 'Tanggal lahir', 'type' => 'date', 'required' => true],
                ['name' => 'waktu_lahir', 'label' => 'Waktu lahir', 'type' => 'time', 'required' => false],
                ['name' => 'anak_ke', 'label' => 'Anak ke-', 'type' => 'number', 'required' => true, 'min' => 1],
                ['name' => 'berat_badan', 'label' => 'Berat badan (kg)', 'type' => 'number', 'required' => false],
                ['name' => 'panjang_badan', 'label' => 'Panjang badan (cm)', 'type' => 'number', 'required' => false],
                ['name' => 'penolong_kelahiran', 'label' => 'Penolong kelahiran', 'type' => 'select',
                    'required' => true, 'allow_other' => true,
                    'options' => ['Dokter', 'Bidan', 'Dukun bayi', 'Lainnya']],
                ['name' => 'nama_ayah', 'label' => 'Nama ayah', 'type' => 'text', 'required' => true],
                ['name' => 'nik_ayah', 'label' => 'NIK ayah', 'type' => 'nik', 'required' => true],
                ['name' => 'nama_ibu', 'label' => 'Nama ibu', 'type' => 'text', 'required' => true],
                ['name' => 'nik_ibu', 'label' => 'NIK ibu', 'type' => 'nik', 'required' => true],
            ]],
            'template_html' => <<<'HTML'
                <p>Dengan ini menerangkan bahwa telah lahir seorang anak:</p>
                <table>
                  <tr><td>Nama</td><td>: {{#upper}}{{form.nama_anak}}{{/upper}}</td></tr>
                  <tr><td>Jenis Kelamin</td><td>: {{form.jenis_kelamin_anak}}</td></tr>
                  <tr><td>Tempat/Tgl Lahir</td><td>: {{form.tempat_lahir_anak}}, {{form.tanggal_lahir_anak}}</td></tr>
                  <tr><td>Anak Ke-</td><td>: {{form.anak_ke}}</td></tr>
                  <tr><td>Penolong Kelahiran</td><td>: {{form.penolong_kelahiran}}</td></tr>
                  <tr><td>Nama Ayah</td><td>: {{#upper}}{{form.nama_ayah}}{{/upper}} (NIK {{form.nik_ayah}})</td></tr>
                  <tr><td>Nama Ibu</td><td>: {{#upper}}{{form.nama_ibu}}{{/upper}} (NIK {{form.nik_ibu}})</td></tr>
                </table>
                <p>Surat keterangan ini dibuat untuk keperluan pengurusan
                <strong>Akta Kelahiran</strong> pada Dinas Kependudukan dan Pencatatan
                Sipil Kabupaten {{kabupaten}}.</p>
                HTML,
        ];
    }

    /** @return array<string, mixed> */
    private function skm(): array
    {
        return [
            'code' => 'SKM',
            'name' => 'Surat Keterangan Kematian',
            'title_on_letter' => 'SURAT KETERANGAN KEMATIAN',
            'description' => 'Keterangan kematian warga untuk pengurusan Akta Kematian. '
                .'Prioritas: layani segera.',
            'sla_days' => 1,
            'validity_months' => null,
            'is_public' => true,
            'is_active' => true,
            'approval_flow' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pemerintahan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            'requirements' => [
                ['label' => 'Fotokopi Kartu Keluarga', 'type' => 'file', 'required' => true],
                ['label' => 'Fotokopi KTP almarhum/almarhumah', 'type' => 'file', 'required' => true],
                ['label' => 'Fotokopi KTP pelapor', 'type' => 'file', 'required' => true],
                ['label' => 'Surat keterangan kematian dari rumah sakit/dokter',
                    'type' => 'file', 'required' => false],
                ['label' => 'Surat pengantar RT/RW', 'type' => 'file', 'required' => true],
            ],
            /*
             * Pemohon surat ini adalah PELAPOR, bukan almarhum — penduduk
             * yang sudah berstatus meninggal tidak dapat menjadi pemohon
             * (lihat LetterRequestService::guardResidentEligible). Data
             * almarhum diambil dari field `resident_meninggal_id` dan
             * tersedia di template dengan prefiks `meninggal.`.
             */
            'form_schema' => ['fields' => [
                ['name' => 'resident_meninggal_id', 'label' => 'Penduduk yang meninggal',
                    'type' => 'resident', 'required' => true],
                ['name' => 'tanggal_kematian', 'label' => 'Tanggal kematian', 'type' => 'date', 'required' => true],
                ['name' => 'waktu_kematian', 'label' => 'Waktu kematian', 'type' => 'time', 'required' => false],
                ['name' => 'tempat_kematian', 'label' => 'Tempat kematian', 'type' => 'text', 'required' => true],
                ['name' => 'sebab_kematian', 'label' => 'Sebab kematian', 'type' => 'select',
                    'required' => true, 'allow_other' => true,
                    'options' => ['Sakit', 'Kecelakaan', 'Usia lanjut', 'Lainnya']],
                ['name' => 'hubungan_pelapor', 'label' => 'Hubungan pelapor dengan almarhum',
                    'type' => 'text', 'required' => true],
            ]],
            'template_html' => <<<'HTML'
                <p>Dengan ini menerangkan bahwa:</p>
                <table>
                  <tr><td>Nama</td><td>: {{#upper}}{{meninggal.nama}}{{/upper}}</td></tr>
                  <tr><td>NIK</td><td>: {{meninggal.nik}}</td></tr>
                  <tr><td>Tempat/Tgl Lahir</td><td>: {{meninggal.tempat_tanggal_lahir}}</td></tr>
                  <tr><td>Alamat</td><td>: {{meninggal.alamat_lengkap}}</td></tr>
                </table>
                <p>Telah meninggal dunia pada
                <strong>{{form.tanggal_kematian}}</strong> di {{form.tempat_kematian}},
                dengan sebab {{form.sebab_kematian}}.</p>
                <p>Keterangan ini disampaikan oleh {{nama}} selaku
                {{form.hubungan_pelapor}} almarhum/almarhumah.</p>
                <p>Surat keterangan ini dibuat untuk keperluan pengurusan
                <strong>Akta Kematian</strong> dan keperluan lain yang sah.</p>
                HTML,
        ];
    }

    /** @return array<string, mixed> */
    private function skp(): array
    {
        return [
            'code' => 'SKP',
            'name' => 'Surat Keterangan Pindah',
            'title_on_letter' => 'SURAT KETERANGAN PINDAH',
            'description' => 'Keterangan kepindahan penduduk untuk pengurusan SKPWNI '
                .'pada Dinas Kependudukan dan Pencatatan Sipil.',
            'sla_days' => 2,
            'validity_months' => null,
            'is_public' => true,
            'is_active' => true,
            'approval_flow' => self::F3_PEMERINTAHAN,
            'requirements' => [
                ...self::SYARAT_DASAR,
                ['label' => 'Kartu Keluarga asli (diserahkan ke Dukcapil)',
                    'type' => 'file', 'required' => true],
            ],
            'form_schema' => ['fields' => [
                ['name' => 'jenis_pindah', 'label' => 'Jenis pindah', 'type' => 'select',
                    'required' => true, 'options' => ['Pindah keluar', 'Pindah datang']],
                ['name' => 'alamat_tujuan', 'label' => 'Alamat tujuan', 'type' => 'text', 'required' => true],
                ['name' => 'desa_tujuan', 'label' => 'Desa/Kelurahan tujuan', 'type' => 'text', 'required' => true],
                ['name' => 'kecamatan_tujuan', 'label' => 'Kecamatan tujuan', 'type' => 'text', 'required' => true],
                ['name' => 'kabupaten_tujuan', 'label' => 'Kabupaten/Kota tujuan', 'type' => 'text', 'required' => true],
                ['name' => 'provinsi_tujuan', 'label' => 'Provinsi tujuan', 'type' => 'text', 'required' => true],
                ['name' => 'alasan_pindah', 'label' => 'Alasan pindah', 'type' => 'select',
                    'required' => true, 'allow_other' => true, 'options' => [
                        'Pekerjaan', 'Pendidikan', 'Perkawinan', 'Keamanan',
                        'Kesehatan', 'Perumahan', 'Lainnya',
                    ]],
                ['name' => 'tanggal_rencana_pindah', 'label' => 'Rencana tanggal pindah',
                    'type' => 'date', 'required' => true],
                ['name' => 'anggota_yang_pindah', 'label' => 'Anggota keluarga yang pindah',
                    'type' => 'multiselect', 'required' => true],
                ['name' => 'status_kk_ditinggalkan', 'label' => 'Status KK yang ditinggalkan',
                    'type' => 'select', 'required' => true,
                    'options' => ['Tidak ada yang tinggal', 'Masih ada anggota']],
            ]],
            'template_html' => <<<'HTML'
                <p>Orang tersebut di atas beserta anggota keluarga yang tercantum di
                bawah ini akan <strong>pindah</strong> ke:</p>
                <table>
                  <tr><td>Alamat Tujuan</td><td>: {{form.alamat_tujuan}}</td></tr>
                  <tr><td>Desa/Kelurahan</td><td>: {{form.desa_tujuan}}</td></tr>
                  <tr><td>Kecamatan</td><td>: {{form.kecamatan_tujuan}}</td></tr>
                  <tr><td>Kabupaten/Kota</td><td>: {{form.kabupaten_tujuan}}</td></tr>
                  <tr><td>Provinsi</td><td>: {{form.provinsi_tujuan}}</td></tr>
                  <tr><td>Alasan Pindah</td><td>: {{form.alasan_pindah}}</td></tr>
                  <tr><td>Rencana Pindah</td><td>: {{form.tanggal_rencana_pindah}}</td></tr>
                  <tr><td>Anggota yang Pindah</td><td>: {{form.anggota_yang_pindah}}</td></tr>
                </table>
                <p>Surat keterangan ini dibuat untuk keperluan pengurusan Surat
                Keterangan Pindah (SKPWNI) pada Dinas Kependudukan dan Pencatatan Sipil.</p>
                HTML,
        ];
    }

    /** @return array<string, mixed> */
    private function skbm(): array
    {
        return [
            'code' => 'SKBM',
            'name' => 'Surat Keterangan Belum Menikah',
            'title_on_letter' => 'SURAT KETERANGAN BELUM MENIKAH',
            'description' => 'Keterangan bahwa warga belum pernah menikah menurut data '
                .'administrasi kependudukan desa.',
            'sla_days' => 1,
            'validity_months' => 3,
            'is_public' => true,
            'is_active' => true,
            'approval_flow' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pemerintahan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            'requirements' => self::SYARAT_DASAR,
            /*
             * Validasi otomatis — docs/13 §5.7.
             *
             * Bila status perkawinan penduduk di basis data BUKAN "Belum
             * Kawin", pengajuan diblokir. Tanpa ini, kantor desa dapat
             * menerbitkan surat yang bertentangan dengan data desa sendiri —
             * dan data itulah yang akan diperiksa instansi tujuan.
             *
             * Ditegakkan LetterRequestService::guardMaritalStatus().
             */
            'form_schema' => [
                'requires_marital_status' => ['belum_kawin'],
                'fields' => [
                    ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'text', 'required' => true],
                    ['name' => 'nama_saksi_1', 'label' => 'Nama saksi 1', 'type' => 'text', 'required' => false],
                    ['name' => 'nama_saksi_2', 'label' => 'Nama saksi 2', 'type' => 'text', 'required' => false],
                ],
            ],
            'template_html' => <<<'HTML'
                <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan menurut
                data administrasi kependudukan serta sepengetahuan kami yang bersangkutan
                <strong>belum pernah menikah</strong> (masih berstatus {{status_perkawinan}}).</p>

                <p>Surat keterangan ini dibuat untuk keperluan
                <strong>{{#upper}}{{form.keperluan}}{{/upper}}</strong>.</p>
                HTML,
        ];
    }

    /** @return array<string, mixed> */
    private function spskck(): array
    {
        return [
            'code' => 'SPSKCK',
            'name' => 'Surat Pengantar SKCK',
            'title_on_letter' => 'SURAT PENGANTAR',
            'description' => 'Pengantar desa untuk pengurusan Surat Keterangan Catatan '
                .'Kepolisian (SKCK) di Polsek/Polres.',
            'sla_days' => 1,
            'validity_months' => 1,
            'is_public' => true,
            'is_active' => true,
            'approval_flow' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pemerintahan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            'requirements' => [
                ...self::SYARAT_DASAR,
                ['label' => 'Foto 4×6 (2 lembar)', 'type' => 'file', 'required' => false],
            ],
            'form_schema' => ['fields' => [
                ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'select',
                    'required' => true, 'allow_other' => true, 'options' => [
                        'Melamar pekerjaan', 'Pendaftaran CPNS/TNI/Polri',
                        'Pendaftaran sekolah', 'Pengurusan visa', 'Lainnya',
                    ]],
            ]],
            /*
             * CATATAN KEBIJAKAN — JANGAN UBAH MENJADI PERNYATAAN MUTLAK.
             *
             * Klausa "berkelakuan baik" di bawah sengaja dibatasi dengan
             * "menurut sepengetahuan kami". Pemerintah desa tidak memiliki
             * akses ke catatan kriminal; wewenang itu ada di kepolisian.
             * Menghapus pembatasan itu berarti desa menyatakan sesuatu yang
             * tidak dapat dibuktikannya (docs/13 §5.8).
             */
            'template_html' => <<<'HTML'
                <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan menurut
                sepengetahuan kami yang bersangkutan <strong>berkelakuan baik</strong>,
                tidak pernah terlibat perkara pidana maupun perbuatan yang melanggar
                hukum serta tidak pernah tersangkut organisasi terlarang.</p>

                <p>Surat pengantar ini diberikan untuk keperluan pengurusan
                <strong>Surat Keterangan Catatan Kepolisian (SKCK)</strong> pada
                Kepolisian Sektor {{kecamatan}} / Kepolisian Resor {{kabupaten}}, guna
                {{#upper}}{{form.keperluan}}{{/upper}}.</p>
                HTML,
        ];
    }

    /* ================= Empat belas surat nonaktif =================
     | Disediakan lengkap tetapi NONAKTIF (docs/13 §6). Petugas
     | mengaktifkan yang relevan dari menu Jenis Surat.
     |
     | Tiga di antaranya — SKAW, SKPT, SKPH — memakai alur tiga tahap dan
     | membawa `warning_note` yang tampil di layar persetujuan. Itu bukan
     | hiasan: ketiganya berimplikasi hukum keperdataan atau keuangan, dan
     | kesalahannya merugikan warga, bukan hanya mengotori data.
     */

    /** @return array<int, array<string, mixed>> */
    private function inactiveTypes(): array
    {
        return [
            $this->simple(
                code: 'SKSP',
                name: 'Surat Keterangan Status Perkawinan',
                title: 'SURAT KETERANGAN STATUS PERKAWINAN',
                description: 'Menerangkan status perkawinan warga sesuai data kependudukan.',
                slaDays: 1,
                validityMonths: 3,
                flow: 'pemerintahan',
                fields: [
                    ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'text', 'required' => true],
                ],
                body: <<<'HTML'
                    <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan
                    menurut data administrasi kependudukan berstatus
                    <strong>{{status_perkawinan}}</strong>.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKJD',
                name: 'Surat Keterangan Janda/Duda',
                title: 'SURAT KETERANGAN JANDA/DUDA',
                description: 'Menerangkan status janda atau duda warga.',
                slaDays: 1,
                validityMonths: 3,
                flow: 'pemerintahan',
                extraRequirements: [
                    ['label' => 'Akta kematian pasangan atau akta cerai', 'type' => 'file', 'required' => true],
                ],
                fields: [
                    ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'text', 'required' => true],
                    ['name' => 'nama_pasangan', 'label' => 'Nama pasangan', 'type' => 'text', 'required' => true],
                    ['name' => 'sebab', 'label' => 'Sebab', 'type' => 'select', 'required' => true,
                        'options' => ['Pasangan meninggal', 'Perceraian']],
                    ['name' => 'tanggal_sebab', 'label' => 'Tanggal kematian/perceraian',
                        'type' => 'date', 'required' => true],
                ],
                schemaExtras: ['requires_marital_status' => ['cerai_hidup', 'cerai_mati']],
                body: <<<'HTML'
                    <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan
                    menurut data administrasi kependudukan berstatus
                    <strong>{{status_perkawinan}}</strong> sejak
                    {{form.tanggal_sebab}}, dengan sebab {{form.sebab}}
                    (pasangan: {{#upper}}{{form.nama_pasangan}}{{/upper}}).</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKAW',
                name: 'Surat Keterangan Ahli Waris',
                title: 'SURAT KETERANGAN AHLI WARIS',
                description: 'Menerangkan daftar ahli waris dari seorang pewaris yang telah '
                    .'meninggal. Berimplikasi hukum keperdataan atas harta.',
                slaDays: 5,
                validityMonths: null,
                flow: 'pemerintahan3',
                isPublic: false,
                requirements: [
                    ['label' => 'Akta kematian pewaris', 'type' => 'file', 'required' => true],
                    ['label' => 'Fotokopi Kartu Keluarga', 'type' => 'file', 'required' => true],
                    ['label' => 'Fotokopi KTP seluruh ahli waris', 'type' => 'file', 'required' => true],
                    ['label' => 'Surat pernyataan ahli waris', 'type' => 'file', 'required' => true],
                    ['label' => 'Identitas 2 (dua) orang saksi', 'type' => 'file', 'required' => true],
                    ['label' => 'Surat pengantar RT/RW', 'type' => 'file', 'required' => true],
                ],
                warning: 'Surat ini berimplikasi hukum atas harta. Pastikan daftar ahli '
                    .'waris sudah lengkap dan benar, berkas fisik telah diperiksa, dan dua '
                    .'saksi hadir. Kesalahan daftar ahli waris memicu sengketa keluarga '
                    .'yang tidak dapat diperbaiki dengan membatalkan surat.',
                fields: [
                    ['name' => 'nama_pewaris', 'label' => 'Nama pewaris', 'type' => 'text', 'required' => true],
                    ['name' => 'nik_pewaris', 'label' => 'NIK pewaris', 'type' => 'nik', 'required' => true],
                    ['name' => 'tanggal_wafat', 'label' => 'Tanggal wafat', 'type' => 'date', 'required' => true],
                    ['name' => 'daftar_ahli_waris', 'label' => 'Daftar ahli waris',
                        'type' => 'textarea', 'required' => true, 'max' => 2000,
                        'hint' => 'Satu baris per ahli waris: nama — NIK — hubungan'],
                    ['name' => 'harta_diwariskan', 'label' => 'Harta yang diwariskan',
                        'type' => 'textarea', 'required' => false, 'max' => 1000],
                    ['name' => 'nama_saksi_1', 'label' => 'Nama saksi 1', 'type' => 'text', 'required' => true],
                    ['name' => 'nama_saksi_2', 'label' => 'Nama saksi 2', 'type' => 'text', 'required' => true],
                ],
                body: <<<'HTML'
                    <p>Dengan ini menerangkan bahwa almarhum/almarhumah
                    <strong>{{#upper}}{{form.nama_pewaris}}{{/upper}}</strong>
                    (NIK {{form.nik_pewaris}}) telah meninggal dunia pada
                    {{form.tanggal_wafat}}, dan meninggalkan ahli waris sebagai berikut:</p>
                    <pre>{{form.daftar_ahli_waris}}</pre>
                    {{#form.harta_diwariskan}}<p>Harta yang diwariskan:
                    {{form.harta_diwariskan}}</p>{{/form.harta_diwariskan}}
                    <p>Keterangan ini dibuat berdasarkan pernyataan ahli waris dengan
                    disaksikan oleh {{#upper}}{{form.nama_saksi_1}}{{/upper}} dan
                    {{#upper}}{{form.nama_saksi_2}}{{/upper}}.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKPH',
                name: 'Surat Keterangan Penghasilan',
                title: 'SURAT KETERANGAN PENGHASILAN',
                description: 'Menerangkan perkiraan penghasilan warga. Umumnya dipakai '
                    .'untuk pengajuan kredit.',
                slaDays: 2,
                validityMonths: 3,
                flow: 'kesejahteraan3',
                extraRequirements: [
                    ['label' => 'Slip gaji (bila pegawai)', 'type' => 'file', 'required' => false],
                ],
                warning: 'Angka penghasilan yang dilebihkan MERUGIKAN WARGA SENDIRI: '
                    .'surat ini dipakai menilai kemampuan membayar kredit, dan cicilan '
                    .'yang terlalu besar berakhir pada gagal bayar. Pastikan angkanya '
                    .'wajar dan sesuai keterangan pemohon.',
                fields: [
                    ['name' => 'pekerjaan', 'label' => 'Pekerjaan', 'type' => 'text', 'required' => true],
                    ['name' => 'penghasilan_per_bulan', 'label' => 'Penghasilan per bulan',
                        'type' => 'currency', 'required' => true],
                    ['name' => 'sumber_penghasilan', 'label' => 'Sumber penghasilan',
                        'type' => 'text', 'required' => true],
                    ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'text', 'required' => true],
                ],
                body: <<<'HTML'
                    <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} yang
                    bekerja sebagai <strong>{{form.pekerjaan}}</strong> dengan sumber
                    penghasilan {{form.sumber_penghasilan}}, dan menurut keterangan yang
                    bersangkutan berpenghasilan rata-rata
                    <strong>{{#rupiah}}{{form.penghasilan_per_bulan}}{{/rupiah}}</strong>
                    ({{#angka_huruf}}{{form.penghasilan_per_bulan}}{{/angka_huruf}})
                    per bulan.</p>
                    <p>Surat keterangan ini dibuat untuk keperluan
                    <strong>{{#upper}}{{form.keperluan}}{{/upper}}</strong>.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKHL',
                name: 'Surat Keterangan Kehilangan',
                title: 'SURAT KETERANGAN KEHILANGAN',
                description: 'Menerangkan kehilangan barang atau dokumen.',
                slaDays: 1,
                validityMonths: 1,
                flow: 'pemerintahan',
                extraRequirements: [
                    ['label' => 'Fotokopi dokumen yang hilang (bila ada)', 'type' => 'file', 'required' => false],
                ],
                fields: [
                    ['name' => 'jenis_barang', 'label' => 'Jenis barang/dokumen', 'type' => 'text', 'required' => true],
                    ['name' => 'ciri_barang', 'label' => 'Ciri-ciri', 'type' => 'textarea',
                        'required' => true, 'max' => 500],
                    ['name' => 'waktu_hilang', 'label' => 'Perkiraan waktu hilang', 'type' => 'text', 'required' => true],
                    ['name' => 'tempat_hilang', 'label' => 'Perkiraan tempat hilang', 'type' => 'text', 'required' => true],
                ],
                body: <<<'HTML'
                    <p>Orang tersebut di atas telah melaporkan kehilangan
                    <strong>{{form.jenis_barang}}</strong> dengan ciri-ciri
                    {{form.ciri_barang}}, diperkirakan hilang pada
                    {{form.waktu_hilang}} di {{form.tempat_hilang}}.</p>
                    <p>Keterangan ini dibuat berdasarkan laporan yang bersangkutan dan
                    bukan merupakan hasil pemeriksaan oleh pemerintah desa.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKTT',
                name: 'Surat Keterangan Tempat Tinggal',
                title: 'SURAT KETERANGAN TEMPAT TINGGAL',
                description: 'Untuk pendatang yang bertempat tinggal di desa ini namun '
                    .'belum berpindah administrasi kependudukan.',
                slaDays: 2,
                validityMonths: 6,
                flow: 'pemerintahan',
                requirements: [
                    ['label' => 'Fotokopi KTP daerah asal', 'type' => 'file', 'required' => true],
                    ['label' => 'Surat pengantar dari daerah asal', 'type' => 'file', 'required' => true],
                    ['label' => 'Surat pengantar RT/RW', 'type' => 'file', 'required' => true],
                ],
                fields: [
                    ['name' => 'alamat_tinggal', 'label' => 'Alamat tinggal di desa ini',
                        'type' => 'text', 'required' => true],
                    ['name' => 'lama_tinggal', 'label' => 'Lama tinggal', 'type' => 'text', 'required' => true],
                    ['name' => 'pemilik_rumah', 'label' => 'Nama pemilik rumah', 'type' => 'text', 'required' => true],
                    ['name' => 'pekerjaan_di_desa', 'label' => 'Pekerjaan di desa ini',
                        'type' => 'text', 'required' => false],
                ],
                body: <<<'HTML'
                    <p>Orang tersebut di atas bertempat tinggal di
                    <strong>{{form.alamat_tinggal}}</strong>, Desa {{desa}}, Kecamatan
                    {{kecamatan}}, selama {{form.lama_tinggal}} di rumah milik
                    {{#upper}}{{form.pemilik_rumah}}{{/upper}}.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SPN',
                name: 'Surat Pengantar Nikah',
                title: 'SURAT PENGANTAR NIKAH',
                description: 'Pengantar desa untuk pengurusan pernikahan di KUA.',
                slaDays: 2,
                validityMonths: 3,
                flow: 'pemerintahan3',
                isPublic: false,
                requirements: [
                    ...self::SYARAT_DASAR,
                    ['label' => 'Fotokopi akta lahir', 'type' => 'file', 'required' => true],
                    ['label' => 'Pas foto', 'type' => 'file', 'required' => true],
                    ['label' => 'Akta cerai / akta kematian pasangan (bila pernah menikah)',
                        'type' => 'file', 'required' => false],
                    ['label' => 'Izin orang tua (bila di bawah umur)', 'type' => 'file', 'required' => false],
                ],
                fields: [
                    ['name' => 'nama_calon', 'label' => 'Nama calon pasangan', 'type' => 'text', 'required' => true],
                    ['name' => 'status_calon', 'label' => 'Status calon pasangan', 'type' => 'text', 'required' => true],
                    ['name' => 'alamat_calon', 'label' => 'Alamat calon pasangan', 'type' => 'text', 'required' => true],
                    ['name' => 'nama_ayah_calon', 'label' => 'Nama ayah calon', 'type' => 'text', 'required' => false],
                    ['name' => 'nama_ibu_calon', 'label' => 'Nama ibu calon', 'type' => 'text', 'required' => false],
                    ['name' => 'tanggal_akad', 'label' => 'Rencana tanggal akad', 'type' => 'date', 'required' => true],
                    ['name' => 'tempat_akad', 'label' => 'Rencana tempat akad', 'type' => 'text', 'required' => true],
                ],
                body: <<<'HTML'
                    <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dengan
                    status {{status_perkawinan}}, dan bermaksud melaksanakan pernikahan
                    dengan:</p>
                    <table>
                      <tr><td>Nama</td><td>: {{#upper}}{{form.nama_calon}}{{/upper}}</td></tr>
                      <tr><td>Status</td><td>: {{form.status_calon}}</td></tr>
                      <tr><td>Alamat</td><td>: {{form.alamat_calon}}</td></tr>
                    </table>
                    <p>Rencana akad: {{form.tanggal_akad}} di {{form.tempat_akad}}.</p>
                    <p>Surat pengantar ini diberikan untuk keperluan pengurusan
                    pernikahan pada Kantor Urusan Agama Kecamatan {{kecamatan}}.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKU-IUMK',
                name: 'Surat Keterangan Usaha untuk IUMK/NIB',
                title: 'SURAT KETERANGAN USAHA',
                description: 'Keterangan usaha dengan data tambahan yang diminta untuk '
                    .'pengurusan IUMK atau NIB.',
                slaDays: 2,
                validityMonths: 12,
                flow: 'pelayanan',
                extraRequirements: [
                    ['label' => 'Foto tempat usaha', 'type' => 'file', 'required' => true],
                ],
                fields: [
                    ['name' => 'nama_usaha', 'label' => 'Nama usaha', 'type' => 'text', 'required' => true],
                    ['name' => 'jenis_usaha', 'label' => 'Jenis usaha', 'type' => 'text', 'required' => true],
                    ['name' => 'alamat_usaha', 'label' => 'Alamat usaha', 'type' => 'text', 'required' => true],
                    ['name' => 'modal_usaha', 'label' => 'Modal usaha', 'type' => 'currency', 'required' => true],
                    ['name' => 'jumlah_pekerja', 'label' => 'Jumlah pekerja', 'type' => 'number',
                        'required' => true, 'min' => 0],
                    ['name' => 'nib', 'label' => 'NIB (bila sudah ada)', 'type' => 'text', 'required' => false],
                ],
                body: <<<'HTML'
                    <p>Orang tersebut di atas memiliki usaha sebagai berikut:</p>
                    <table>
                      <tr><td>Nama Usaha</td><td>: {{form.nama_usaha}}</td></tr>
                      <tr><td>Jenis Usaha</td><td>: {{form.jenis_usaha}}</td></tr>
                      <tr><td>Alamat Usaha</td><td>: {{form.alamat_usaha}}</td></tr>
                      <tr><td>Modal Usaha</td><td>: {{#rupiah}}{{form.modal_usaha}}{{/rupiah}}</td></tr>
                      <tr><td>Jumlah Pekerja</td><td>: {{form.jumlah_pekerja}} orang</td></tr>
                      {{#form.nib}}<tr><td>NIB</td><td>: {{form.nib}}</td></tr>{{/form.nib}}
                    </table>
                    <p>Surat keterangan ini dibuat untuk keperluan pengurusan
                    <strong>Izin Usaha Mikro Kecil (IUMK) / Nomor Induk Berusaha
                    (NIB)</strong>.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SIK',
                name: 'Surat Izin/Rekomendasi Keramaian',
                title: 'SURAT REKOMENDASI KERAMAIAN',
                description: 'Rekomendasi desa untuk kegiatan yang mengumpulkan orang banyak.',
                slaDays: 3,
                validityMonths: null,
                flow: 'pemerintahan3',
                requirements: [
                    ['label' => 'Surat pengantar RT/RW', 'type' => 'file', 'required' => true],
                    ['label' => 'Susunan acara', 'type' => 'file', 'required' => true],
                    ['label' => 'Persetujuan lingkungan/tetangga', 'type' => 'file', 'required' => true],
                ],
                fields: [
                    ['name' => 'jenis_kegiatan', 'label' => 'Jenis kegiatan', 'type' => 'text', 'required' => true],
                    ['name' => 'tanggal_kegiatan', 'label' => 'Tanggal kegiatan', 'type' => 'date', 'required' => true],
                    ['name' => 'waktu_kegiatan', 'label' => 'Waktu kegiatan', 'type' => 'text', 'required' => true],
                    ['name' => 'lokasi_kegiatan', 'label' => 'Lokasi kegiatan', 'type' => 'text', 'required' => true],
                    ['name' => 'jumlah_peserta', 'label' => 'Perkiraan jumlah peserta',
                        'type' => 'number', 'required' => true, 'min' => 1],
                    ['name' => 'penanggung_jawab', 'label' => 'Penanggung jawab', 'type' => 'text', 'required' => true],
                ],
                body: <<<'HTML'
                    <p>Pemerintah Desa {{desa}} memberikan rekomendasi atas rencana
                    kegiatan sebagai berikut:</p>
                    <table>
                      <tr><td>Jenis Kegiatan</td><td>: {{form.jenis_kegiatan}}</td></tr>
                      <tr><td>Hari/Tanggal</td><td>: {{form.tanggal_kegiatan}}</td></tr>
                      <tr><td>Waktu</td><td>: {{form.waktu_kegiatan}}</td></tr>
                      <tr><td>Lokasi</td><td>: {{form.lokasi_kegiatan}}</td></tr>
                      <tr><td>Perkiraan Peserta</td><td>: {{form.jumlah_peserta}} orang</td></tr>
                      <tr><td>Penanggung Jawab</td><td>: {{#upper}}{{form.penanggung_jawab}}{{/upper}}</td></tr>
                    </table>
                    <p>Rekomendasi ini diberikan dengan ketentuan penyelenggara menjaga
                    ketertiban dan keamanan, serta menaati ketentuan yang berlaku.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKPT',
                name: 'Surat Keterangan Penguasaan Fisik Bidang Tanah',
                title: 'SURAT KETERANGAN PENGUASAAN FISIK BIDANG TANAH',
                description: 'Menerangkan penguasaan fisik atas sebidang tanah. Sumber '
                    .'sengketa tanah paling umum — wajib pengecekan lapangan.',
                slaDays: 7,
                validityMonths: null,
                flow: 'pemerintahan3',
                isPublic: false,
                requiresFieldCheck: true,
                requirements: [
                    ['label' => 'Bukti perolehan tanah', 'type' => 'file', 'required' => true],
                    ['label' => 'Identitas 2 (dua) saksi batas', 'type' => 'file', 'required' => true],
                    ['label' => 'SPPT PBB', 'type' => 'file', 'required' => true],
                    ['label' => 'Surat pengantar RT/RW', 'type' => 'file', 'required' => true],
                ],
                warning: 'WAJIB pengecekan lapangan oleh Kasi dan kehadiran dua saksi '
                    .'batas. Surat ini TIDAK BOLEH terbit hanya dari berkas. Keterangan '
                    .'batas yang salah menjadi dasar sengketa tanah bertahun-tahun yang '
                    .'tidak selesai dengan membatalkan surat.',
                fields: [
                    ['name' => 'letak_tanah', 'label' => 'Letak tanah', 'type' => 'text', 'required' => true],
                    ['name' => 'luas_tanah', 'label' => 'Luas tanah (m²)', 'type' => 'number',
                        'required' => true, 'min' => 1],
                    ['name' => 'batas_utara', 'label' => 'Batas utara', 'type' => 'text', 'required' => true],
                    ['name' => 'batas_timur', 'label' => 'Batas timur', 'type' => 'text', 'required' => true],
                    ['name' => 'batas_selatan', 'label' => 'Batas selatan', 'type' => 'text', 'required' => true],
                    ['name' => 'batas_barat', 'label' => 'Batas barat', 'type' => 'text', 'required' => true],
                    ['name' => 'status_tanah', 'label' => 'Status tanah', 'type' => 'text', 'required' => true],
                    ['name' => 'riwayat_perolehan', 'label' => 'Riwayat perolehan',
                        'type' => 'textarea', 'required' => true, 'max' => 1000],
                    ['name' => 'pemilik_sebelumnya', 'label' => 'Nama pemilik sebelumnya',
                        'type' => 'text', 'required' => false],
                    ['name' => 'nama_saksi_batas_1', 'label' => 'Saksi batas 1', 'type' => 'text', 'required' => true],
                    ['name' => 'nama_saksi_batas_2', 'label' => 'Saksi batas 2', 'type' => 'text', 'required' => true],
                ],
                body: <<<'HTML'
                    <p>Orang tersebut di atas menguasai secara fisik sebidang tanah
                    dengan keterangan sebagai berikut:</p>
                    <table>
                      <tr><td>Letak</td><td>: {{form.letak_tanah}}</td></tr>
                      <tr><td>Luas</td><td>: {{form.luas_tanah}} m²</td></tr>
                      <tr><td>Batas Utara</td><td>: {{form.batas_utara}}</td></tr>
                      <tr><td>Batas Timur</td><td>: {{form.batas_timur}}</td></tr>
                      <tr><td>Batas Selatan</td><td>: {{form.batas_selatan}}</td></tr>
                      <tr><td>Batas Barat</td><td>: {{form.batas_barat}}</td></tr>
                      <tr><td>Status Tanah</td><td>: {{form.status_tanah}}</td></tr>
                      <tr><td>Riwayat Perolehan</td><td>: {{form.riwayat_perolehan}}</td></tr>
                    </table>
                    <p>Keterangan batas disaksikan oleh
                    {{#upper}}{{form.nama_saksi_batas_1}}{{/upper}} dan
                    {{#upper}}{{form.nama_saksi_batas_2}}{{/upper}}, dan telah dilakukan
                    pengecekan lapangan oleh perangkat Desa {{desa}}.</p>
                    <p>Surat keterangan ini BUKAN bukti kepemilikan hak atas tanah dan
                    tidak mengurangi hak pihak lain yang dapat membuktikan sebaliknya.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKBI',
                name: 'Surat Keterangan Beda Identitas',
                title: 'SURAT KETERANGAN BEDA IDENTITAS',
                description: 'Menerangkan perbedaan penulisan identitas antara dua dokumen '
                    .'milik orang yang sama.',
                slaDays: 2,
                validityMonths: 6,
                flow: 'pemerintahan',
                requirements: [
                    ...self::SYARAT_DASAR,
                    ['label' => 'Fotokopi kedua dokumen yang berbeda', 'type' => 'file', 'required' => true],
                ],
                fields: [
                    ['name' => 'data_yang_berbeda', 'label' => 'Data yang berbeda', 'type' => 'text', 'required' => true],
                    ['name' => 'dokumen_a', 'label' => 'Nama dokumen A', 'type' => 'text', 'required' => true],
                    ['name' => 'nilai_a', 'label' => 'Tertulis di dokumen A', 'type' => 'text', 'required' => true],
                    ['name' => 'dokumen_b', 'label' => 'Nama dokumen B', 'type' => 'text', 'required' => true],
                    ['name' => 'nilai_b', 'label' => 'Tertulis di dokumen B', 'type' => 'text', 'required' => true],
                    ['name' => 'yang_benar', 'label' => 'Yang dianggap benar', 'type' => 'text', 'required' => true],
                ],
                body: <<<'HTML'
                    <p>Dengan ini menerangkan bahwa terdapat perbedaan penulisan
                    <strong>{{form.data_yang_berbeda}}</strong> atas nama orang yang sama,
                    yaitu:</p>
                    <table>
                      <tr><td>{{form.dokumen_a}}</td><td>: {{form.nilai_a}}</td></tr>
                      <tr><td>{{form.dokumen_b}}</td><td>: {{form.nilai_b}}</td></tr>
                    </table>
                    <p>Keduanya adalah orang yang sama, dan data yang dianggap benar
                    adalah <strong>{{form.yang_benar}}</strong>.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKBK',
                name: 'Surat Keterangan Belum Memiliki Rumah',
                title: 'SURAT KETERANGAN BELUM MEMILIKI RUMAH',
                description: 'Menerangkan bahwa warga belum memiliki rumah atau tempat '
                    .'tinggal tetap.',
                slaDays: 2,
                validityMonths: 3,
                flow: 'kesejahteraan',
                fields: [
                    ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'text', 'required' => true],
                    ['name' => 'keterangan_tempat_tinggal', 'label' => 'Tempat tinggal saat ini',
                        'type' => 'textarea', 'required' => true, 'max' => 500],
                ],
                body: <<<'HTML'
                    <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan
                    menurut sepengetahuan kami <strong>belum memiliki rumah atau tempat
                    tinggal tetap</strong>. Saat ini yang bersangkutan
                    {{form.keterangan_tempat_tinggal}}.</p>
                    <p>Surat keterangan ini dibuat untuk keperluan
                    <strong>{{#upper}}{{form.keperluan}}{{/upper}}</strong>.</p>
                    HTML,
            ),
            $this->simple(
                code: 'SRK',
                name: 'Surat Rekomendasi / Pengantar Umum',
                title: 'SURAT REKOMENDASI',
                description: 'Surat pengantar atau rekomendasi umum dengan isi yang '
                    .'disusun petugas. Hanya untuk layanan loket.',
                slaDays: 1,
                validityMonths: null,
                flow: 'pemerintahan',
                isPublic: false,
                requirements: [
                    ['label' => 'Surat pengantar RT/RW', 'type' => 'file', 'required' => true],
                ],
                /*
                 * `isi_rekomendasi` adalah teks bebas yang ditulis petugas,
                 * dan satu-satunya field semacam itu di seluruh katalog.
                 * Karena itu surat ini TIDAK dibuka untuk pengajuan daring
                 * (is_public false): isi surat resmi tidak boleh ditentukan
                 * pemohonnya sendiri.
                 */
                fields: [
                    ['name' => 'ditujukan_kepada', 'label' => 'Ditujukan kepada', 'type' => 'text', 'required' => true],
                    ['name' => 'hal', 'label' => 'Hal', 'type' => 'text', 'required' => true],
                    ['name' => 'isi_rekomendasi', 'label' => 'Isi rekomendasi',
                        'type' => 'textarea', 'required' => true, 'max' => 2000],
                ],
                body: <<<'HTML'
                    <p>Kepada: <strong>{{form.ditujukan_kepada}}</strong><br>
                    Hal: <strong>{{form.hal}}</strong></p>
                    <p>{{form.isi_rekomendasi}}</p>
                    HTML,
            ),
            $this->simple(
                code: 'SKBB',
                name: 'Surat Keterangan Bersih Diri',
                title: 'SURAT KETERANGAN BERSIH DIRI',
                description: 'Menerangkan bahwa warga tidak terlibat penyalahgunaan '
                    .'narkotika menurut sepengetahuan pemerintah desa.',
                slaDays: 2,
                validityMonths: 3,
                flow: 'pemerintahan',
                fields: [
                    ['name' => 'keperluan', 'label' => 'Keperluan', 'type' => 'text', 'required' => true],
                    ['name' => 'instansi_tujuan', 'label' => 'Instansi tujuan', 'type' => 'text', 'required' => true],
                ],
                /*
                 * Sama dengan SPSKCK: redaksinya dibatasi "menurut
                 * sepengetahuan kami". Pemerintah desa tidak melakukan
                 * pemeriksaan medis maupun pemeriksaan catatan kriminal.
                 */
                body: <<<'HTML'
                    <p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan
                    menurut sepengetahuan kami yang bersangkutan <strong>tidak terlibat
                    penyalahgunaan narkotika, psikotropika, dan zat adiktif
                    lainnya</strong>.</p>
                    <p>Surat keterangan ini dibuat untuk keperluan
                    {{#upper}}{{form.keperluan}}{{/upper}} pada
                    {{form.instansi_tujuan}}.</p>
                    HTML,
            ),
        ];
    }

    /**
     * Pembantu penyusun entri katalog.
     *
     * Ada agar perbedaan antar jenis surat terlihat dalam satu layar: bila
     * setiap jenis ditulis utuh, perbedaan yang penting — alur, SLA, apakah
     * boleh daring — tenggelam di antara kemiripan yang berulang.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, array<string, mixed>>|null  $requirements  menggantikan syarat dasar
     * @param  array<int, array<string, mixed>>  $extraRequirements  menambah syarat dasar
     * @param  array<string, mixed>  $schemaExtras
     * @return array<string, mixed>
     */
    private function simple(
        string $code,
        string $name,
        string $title,
        string $description,
        int $slaDays,
        ?int $validityMonths,
        string $flow,
        array $fields,
        string $body,
        ?array $requirements = null,
        array $extraRequirements = [],
        array $schemaExtras = [],
        bool $isPublic = true,
        bool $requiresFieldCheck = false,
        ?string $warning = null,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'title_on_letter' => $title,
            'description' => $description,
            'sla_days' => $slaDays,
            'validity_months' => $validityMonths,
            'is_public' => $isPublic,
            'is_active' => false,
            'requires_field_check' => $requiresFieldCheck,
            'warning_note' => $warning,
            'approval_flow' => $this->flow($flow),
            'requirements' => $requirements ?? [...self::SYARAT_DASAR, ...$extraRequirements],
            'form_schema' => [...$schemaExtras, 'fields' => $fields],
            'template_html' => $body,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function flow(string $name): array
    {
        return match ($name) {
            'satu_tahap' => self::F1,
            'pemerintahan3' => self::F3_PEMERINTAHAN,
            'kesejahteraan3' => self::F3_KESEJAHTERAAN,
            'pelayanan3' => self::F3_PELAYANAN,
            'kesejahteraan' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_kesejahteraan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            'pelayanan' => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pelayanan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
            default => [
                ['step' => 1, 'action' => 'verify', 'role' => 'kasi_pemerintahan'],
                ['step' => 2, 'action' => 'approve', 'role' => 'kepala_desa'],
            ],
        };
    }
}
