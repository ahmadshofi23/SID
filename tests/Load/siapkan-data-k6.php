<?php

declare(strict_types=1);

/*
 * Siapkan `tests/Load/data-uji.json` untuk skrip k6.
 *
 * MENGAPA BERKAS, DAN BUKAN k6 YANG MENCARINYA SENDIRI
 * ---------------------------------------------------
 * Skrip k6 butuh id pengaduan yang nyata untuk menguji penulisan. Satu-satunya
 * cara k6 mendapatkannya dari aplikasi adalah dengan mengurai id dari HTML
 * halaman antrean — dan halaman itu adalah halaman Inertia, sehingga id-nya
 * terbenam di dalam atribut `data-page` bersama id wilayah, id pengguna, dan
 * id lampiran. Regex UUID di atas HTML itu akan kadang-kadang mengenai id
 * yang salah, dan uji beban yang 3% permintaannya menghasilkan 404 melaporkan
 * "galat 3%" yang bukan galat aplikasi. Lebih buruk: angka itu melewati
 * ambang galat < 0,1% dan membuat uji gagal tanpa ada yang rusak.
 *
 * Jalankan SETELAH `sid:seed-volume`:
 *   DB_DATABASE=sid_desa_volume php tests/Load/siapkan-data-k6.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$pengaduan = DB::table('complaints')
    ->whereIn('status', ['baru', 'diterima', 'diproses'])
    ->orderBy('created_at')
    ->limit(200)
    ->pluck('id')
    ->all();

if ($pengaduan === []) {
    fwrite(STDERR, "Tidak ada pengaduan terbuka. Jalankan sid:seed-volume lebih dulu.\n");
    exit(1);
}

/*
 * Kata pencarian diambil dari nama yang BENAR-BENAR ada di basis data, bukan
 * ditulis tangan. Kata yang tidak cocok dengan siapa pun dijawab MySQL dengan
 * nol baris — kueri tercepat yang mungkin, dan karena itu pengukuran
 * pencarian yang paling tidak berguna.
 */
$kata = [];

/*
 * `inRandomOrder()` DIPERLUKAN, dan ini bekas kesalahan.
 *
 * `limit(40)` tanpa pengurutan membuat MySQL mengembalikan empat puluh baris
 * pertama menurut indeks yang kebetulan dipilihnya — di sini
 * `idx_residents_name`, sehingga keempat puluh nama itu adalah nama yang
 * sama secara abjad dan hasilnya SATU kata pencarian untuk seluruh uji.
 * Uji beban kemudian mencari kata yang sama berulang kali, yang dijawab
 * cache MySQL, dan angka pencarian yang terukur menjadi angka cache.
 */
foreach (DB::table('residents')->inRandomOrder()->limit(120)->pluck('full_name_norm') as $nama) {
    $bagian = explode(' ', (string) $nama);
    $kata[] = $bagian[0];

    // Potongan di tengah kata memaksa cabang LIKE '%…%' — kriteria §4.5.
    if (strlen($bagian[0]) > 4) {
        $kata[] = substr($bagian[0], 1, 4);
    }
}

$data = [
    'pengaduan_terbuka' => $pengaduan,
    'kata_pencarian' => array_values(array_unique($kata)),
    'wilayah' => DB::table('regions')->where('level', 'rt')->pluck('id')->all(),
    'disiapkan_pada' => now()->toIso8601String(),
    'basis_data' => DB::connection()->getDatabaseName(),
];

$path = __DIR__.'/data-uji.json';
file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf(
    "Ditulis %s\n  %d pengaduan terbuka, %d kata pencarian, %d wilayah (basis data: %s)\n",
    $path,
    count($data['pengaduan_terbuka']),
    count($data['kata_pencarian']),
    count($data['wilayah']),
    $data['basis_data'],
);
