<?php

declare(strict_types=1);

/*
 * Pengukur kueri pada volume penuh — docs/09-testing-qa.md §4.5 (baris
 * "Volume"), NFR-PERF-07.
 *
 * MENGAPA SKRIP BERDIRI SENDIRI DAN BUKAN PERINTAH ARTISAN
 * --------------------------------------------------------
 * Pengukuran ini harus menjalankan kueri mentah ke `residents`, `families`,
 * dan `audit_logs` untuk mengetahui biaya sebenarnya `LIKE '%…%'` — tepat
 * yang dilarang tests/Architecture/RepositoryBoundaryTest.php di
 * app/Console/Commands, dengan alasan yang masih berlaku penuh (KEP-002).
 * Alat ukur tidak punya alasan untuk hidup di dalam aplikasi: ia tidak pernah
 * dipanggil permintaan HTTP, tidak pernah mengembalikan baris ke siapa pun,
 * dan tidak ikut ter-deploy.
 *
 * CARA PAKAI
 *   DB_DATABASE=sid_desa_volume php tests/Load/ukur-kueri.php
 *   DB_DATABASE=sid_desa_volume php tests/Load/ukur-kueri.php --ulang=20
 *
 * Yang dilaporkan adalah MEDIAN dan p95 dari `--ulang` kali percobaan, bukan
 * satu kali jalan. Satu kali jalan pada basis data mengukur keberuntungan
 * isi buffer pool, bukan kinerja kuerinya.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$repeats = 10;

foreach ($argv as $arg) {
    if (preg_match('/^--ulang=(\d+)$/', $arg, $m) === 1) {
        $repeats = max(1, (int) $m[1]);
    }
}

$database = (string) DB::connection()->getDatabaseName();

echo "Basis data : {$database}\n";
echo 'Penduduk   : '.number_format((int) DB::table('residents')->count(), 0, ',', '.')."\n";
echo 'Keluarga   : '.number_format((int) DB::table('families')->count(), 0, ',', '.')."\n";
echo 'Audit log  : '.number_format((int) DB::table('audit_logs')->count(), 0, ',', '.')."\n";
echo "Ulangan    : {$repeats}\n\n";

/**
 * Jalankan satu kueri `$repeats` kali dan laporkan sebarannya.
 *
 * @param  callable(): mixed  $run
 */
function ukur(string $label, callable $run, float $limitMs, int $repeats): void
{
    $samples = [];

    // Satu kali pemanasan DIBUANG. Kueri pertama membayar pengisian buffer
    // pool dan kompilasi rencana; memasukkannya ke dalam p95 membuat angka
    // yang dilaporkan menggambarkan awal mula server, bukan pemakaian harian.
    $run();

    for ($i = 0; $i < $repeats; $i++) {
        $start = hrtime(true);
        $run();
        $samples[] = (hrtime(true) - $start) / 1_000_000;
    }

    sort($samples);
    $median = $samples[(int) floor(count($samples) / 2)];
    $p95 = $samples[min(count($samples) - 1, (int) ceil(count($samples) * 0.95) - 1)];
    $verdict = $p95 <= $limitMs ? 'LULUS' : 'TIDAK';

    printf(
        "%-52s median %8.1f ms   p95 %8.1f ms   batas %6.0f ms   %s\n",
        $label, $median, $p95, $limitMs, $verdict
    );
}

$namaUmum = 'ahmad';          // berawalan — dapat memakai indeks
$potongan = 'hmad';           // di tengah — memaksa pemindaian
$nikContoh = DB::table('residents')->value('nik_hash');

/* ---------------- Pencarian penduduk ---------------- */

echo "— Pencarian penduduk (FR-PDK-04/05) —\n";

ukur('NIK persis (nik_hash, lewat indeks)', fn () => DB::table('residents')
    ->where('nik_hash', $nikContoh)
    ->whereNull('deleted_at')
    ->limit(50)->get(), 200, $repeats);

ukur("Nama berawalan  LIKE 'ahmad%'", fn () => DB::table('residents')
    ->where('full_name_norm', 'like', $namaUmum.'%')
    ->whereNull('deleted_at')
    ->orderBy('full_name_norm')->limit(50)->get(), 200, $repeats);

ukur("Nama mengandung LIKE '%hmad%'  (kriteria §4.5)", fn () => DB::table('residents')
    ->where('full_name_norm', 'like', '%'.$potongan.'%')
    ->whereNull('deleted_at')
    ->orderBy('full_name_norm')->limit(50)->get(), 200, $repeats);

/*
 * Bentuk OR yang BENAR-BENAR dipakai ResidentRepository::search().
 *
 * Diukur terpisah dari dua di atas dengan sengaja: cabang berawalan di dalam
 * OR tidak dapat memakai indeks (MySQL tidak dapat menggabungkan rentang
 * indeks dengan pemindaian penuh pada kolom yang sama), sehingga biaya nyata
 * yang dirasakan petugas adalah angka INI, bukan angka `LIKE 'ahmad%'`.
 */
ukur('Bentuk OR di repository (berawalan OR mengandung)', fn () => DB::table('residents')
    ->where(function ($q) use ($namaUmum): void {
        $q->where('full_name_norm', 'like', $namaUmum.'%')
            ->orWhere('full_name_norm', 'like', '%'.$namaUmum.'%');
    })
    ->whereNull('deleted_at')
    ->orderBy('full_name_norm')->limit(50)->get(), 200, $repeats);

ukur('Daftar penduduk halaman 1 + relasi wilayah', fn () => DB::table('residents')
    ->leftJoin('regions', 'residents.region_id', '=', 'regions.id')
    ->leftJoin('families', 'residents.family_id', '=', 'families.id')
    ->whereNull('residents.deleted_at')
    ->orderBy('residents.full_name_norm')->limit(50)
    ->get(['residents.id', 'residents.full_name', 'regions.name', 'families.address']), 500, $repeats);

/* ---------------- Statistik & laporan ---------------- */

echo "\n— Statistik & laporan (FR-LAP, NFR-PERF-01) —\n";

ukur('Rekap status penduduk (GROUP BY status)', fn () => DB::table('residents')
    ->selectRaw('status, COUNT(*) n')
    ->whereNull('deleted_at')->groupBy('status')->get(), 500, $repeats);

ukur('Piramida umur (GROUP BY rentang tanggal lahir)', fn () => DB::table('residents')
    ->selectRaw('FLOOR(TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) / 5) kel, gender, COUNT(*) n')
    ->whereNull('deleted_at')->where('status', 'aktif')
    ->groupBy('kel', 'gender')->get(), 500, $repeats);

ukur('Rekap pendidikan × wilayah', fn () => DB::table('residents')
    ->selectRaw('region_id, education_code, COUNT(*) n')
    ->whereNull('deleted_at')->where('status', 'aktif')
    ->groupBy('region_id', 'education_code')->get(), 500, $repeats);

ukur('Buku Induk satu RT (urut nama, semua kolom)', function () {
    $rt = DB::table('regions')->where('level', 'rt')->value('id');

    return DB::table('residents')
        ->where('region_id', $rt)->whereNull('deleted_at')
        ->orderBy('full_name_norm')->get();
}, 1000, $repeats);

/* ---------------- Audit log ---------------- */

echo "\n— Audit log 1,5 juta baris (FR-ADM-04) —\n";

ukur('Log aktivitas halaman 1 (ORDER BY created_at DESC)', fn () => DB::table('audit_logs')
    ->orderByDesc('created_at')->limit(50)->get(), 500, $repeats);

ukur('Log satu aksi, rentang 30 hari', fn () => DB::table('audit_logs')
    ->where('action', 'data.pii_revealed')
    ->where('created_at', '>=', now()->subDays(30))
    ->orderByDesc('created_at')->limit(50)->get(), 500, $repeats);

ukur('Log satu pelaku, rentang 1 tahun', function () {
    $actor = DB::table('audit_logs')->value('actor_id');

    return DB::table('audit_logs')
        ->where('actor_id', $actor)
        ->where('created_at', '>=', now()->subYear())
        ->orderByDesc('created_at')->limit(50)->get();
}, 500, $repeats);

/*
 * Hitungan total audit log diukur terpisah karena ia adalah satu-satunya
 * kueri yang WAJIB menyentuh seluruh tabel. Paginator Laravel memanggilnya
 * pada setiap pembukaan halaman log aktivitas.
 */
ukur('COUNT(*) seluruh audit log (dipakai paginator)', fn () => DB::table('audit_logs')
    ->count(), 500, $repeats);

/* ---------------- Antrean ---------------- */

echo "\n— Antrean surat & pengaduan —\n";

ukur('Antrean surat (status + tenggat)', fn () => DB::table('letter_requests')
    ->whereIn('status', ['diajukan', 'diverifikasi'])
    ->orderBy('due_at')->limit(50)->get(), 500, $repeats);

ukur('Antrean pengaduan (status + tenggat triase)', fn () => DB::table('complaints')
    ->whereIn('status', ['baru', 'diterima', 'diproses'])
    ->orderBy('triage_due_at')->limit(50)->get(), 500, $repeats);

echo "\n";
