<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\VolumeSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bangun tumpukan data untuk uji beban & volume — docs/09-testing-qa.md §4.5,
 * NFR-PERF-07 (20.000 penduduk, 1,5 juta baris audit log).
 *
 * DUA PENJAGA, DAN KEDUANYA ADA KARENA ALASAN YANG BERBEDA
 * -------------------------------------------------------
 * 1. APP_ENV=production DITOLAK MENTAH-MENTAH, tanpa opsi pemaksa.
 *    Perintah ini menyisipkan 20.000 penduduk fiktif ke tabel yang menjadi
 *    dasar Buku Induk, statistik desa, dan setiap surat yang terbit. Di
 *    produksi akibatnya tidak dapat dibatalkan dengan rapi: penduduk fiktif
 *    sudah bercampur dengan penduduk nyata dalam satu tabel, tanpa penanda
 *    apa pun yang membedakan keduanya selain pola nomor NIK — dan satu
 *    laporan yang sudah dicetak dengan angka itu tidak dapat ditarik kembali.
 *    Opsi `--paksa` SENGAJA tidak disediakan: satu-satunya cara aman
 *    menjalankannya di produksi adalah tidak menjalankannya.
 *
 * 2. BASIS DATA BAWAANNYA BUKAN basis data pengembangan.
 *    `sid_desa` berisi data contoh yang dipakai seluruh pekerjaan harian dan
 *    sebagian uji manual. Menumpuk 20.000 penduduk di atasnya membuat setiap
 *    layar menjadi lambat dan setiap data contoh yang dikenali tenggelam di
 *    antara ribuan baris sintetis. Karena itu perintah ini MENGALIHKAN
 *    koneksi `mysql` ke basis data lain (bawaan: `sid_desa_volume`) sebelum
 *    menyentuh apa pun. Mengenai `sid_desa` tetap mungkin, tetapi hanya
 *    dengan menuliskan namanya sendiri: `--basis-data=sid_desa`.
 *
 * CARA PAKAI SINGKAT (rinci: tests/Load/README.md)
 * ------------------------------------------------
 *   php artisan sid:seed-volume --segar
 *   DB_DATABASE=sid_desa_volume php artisan serve
 *   k6 run tests/Load/beban-campuran.js
 *   php artisan sid:seed-volume --bersihkan
 */
final class SeedVolume extends Command
{
    protected $signature = 'sid:seed-volume
        {--penduduk=20000 : Jumlah penduduk yang disemai}
        {--audit=1500000 : Jumlah baris audit log}
        {--surat=2000 : Jumlah pengajuan surat}
        {--pengaduan=2000 : Jumlah pengaduan}
        {--basis-data= : Basis data tujuan (bawaan: sid_desa_volume)}
        {--segar : Buat basis data, migrasi, dan semai data dasar lebih dulu}
        {--bersihkan : HAPUS basis data volume, jangan semai apa pun}';

    protected $description = 'Menyemai 20.000 penduduk & 1,5 juta audit log untuk uji beban (docs/09 §4.5)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->components->error(
                'Perintah ini menolak berjalan di APP_ENV=production. '
                .'Lihat keterangan kelasnya: penduduk fiktif yang sudah '
                .'bercampur dengan penduduk nyata tidak dapat dipisahkan lagi.'
            );

            return self::FAILURE;
        }

        $target = $this->targetDatabase();

        if ($target === (string) config('database.connections.mysql.database')
            && ! $this->option('basis-data')) {
            // Tidak dapat terjadi dengan bawaan di atas; dijaga agar perubahan
            // konfigurasi kelak tidak diam-diam menumpuk ke basis data kerja.
            $this->components->error('Basis data tujuan sama dengan basis data kerja.');

            return self::FAILURE;
        }

        $this->useDatabase($target);

        if ($this->option('bersihkan')) {
            return $this->dropDatabase($target);
        }

        $this->components->info("Basis data tujuan: {$target}");

        if ($this->option('segar') && ! $this->prepareDatabase($target)) {
            return self::FAILURE;
        }

        if (! $this->databaseReady($target)) {
            return self::FAILURE;
        }

        $started = microtime(true);

        $seeder = (new VolumeSeeder)->withOutput($this->getOutput());
        $seeder->run(
            residents: (int) $this->option('penduduk'),
            auditRows: (int) $this->option('audit'),
            letterRequests: (int) $this->option('surat'),
            complaints: (int) $this->option('pengaduan'),
        );

        $this->newLine();
        $this->components->info(sprintf(
            'Selesai dalam %s detik.', number_format(microtime(true) - $started, 1, ',', '.')
        ));
        $this->line("Jalankan server uji dengan: DB_DATABASE={$target} php artisan serve");

        return self::SUCCESS;
    }

    private function targetDatabase(): string
    {
        $value = $this->option('basis-data');

        return is_string($value) && $value !== '' ? $value : 'sid_desa_volume';
    }

    /**
     * Alihkan koneksi `mysql` ke basis data volume.
     *
     * Dilakukan dengan mengubah konfigurasi lalu `DB::purge()`, BUKAN dengan
     * menambah koneksi baru di config/database.php. Alasannya: koneksi kedua
     * yang menyalin selusin nilai (termasuk `modes` sql_mode yang ketat,
     * docs/05 §6) akan menyimpang dari aslinya pada perubahan berikutnya, dan
     * uji volume yang berjalan dengan sql_mode berbeda dari produksi mengukur
     * basis data yang lain.
     */
    private function useDatabase(string $database): void
    {
        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');
    }

    private function prepareDatabase(string $database): bool
    {
        /*
         * CREATE DATABASE dijalankan lewat koneksi TANPA nama basis data.
         * Koneksi yang menunjuk basis data yang belum ada gagal saat membuka
         * sambungan, jadi ia tidak dapat dipakai untuk membuatnya.
         */
        config(['database.connections.mysql_root' => array_merge(
            (array) config('database.connections.mysql'),
            ['database' => null],
        )]);

        try {
            DB::connection('mysql_root')->statement(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
                $database,
                (string) config('database.connections.mysql.charset'),
                (string) config('database.connections.mysql.collation'),
            ));
        } catch (Throwable $e) {
            $this->components->error('Gagal membuat basis data: '.$e->getMessage());

            return false;
        }

        $this->components->task('Migrasi', function () {
            Artisan::call('migrate', ['--force' => true], $this->getOutput());

            return true;
        });

        /*
         * db:seed DIPERLUKAN, bukan opsional. Penyemai volume menempelkan
         * penduduk pada hierarki wilayah, jenis surat, peran, dan akun yang
         * sudah ada — dan skrip k6 masuk memakai akun demo. Tanpa langkah ini
         * uji beban hanya akan mengukur halaman login.
         */
        $this->components->task('Data dasar & akun demo', function () {
            Artisan::call('db:seed', ['--force' => true], $this->getOutput());

            return true;
        });

        return true;
    }

    private function databaseReady(string $database): bool
    {
        try {
            DB::connection('mysql')->getPdo();
        } catch (Throwable) {
            $this->components->error(
                "Basis data `{$database}` tidak dapat dibuka. Jalankan dengan --segar."
            );

            return false;
        }

        return true;
    }

    private function dropDatabase(string $database): int
    {
        if (! $this->confirm("Hapus seluruh basis data `{$database}`?", false)) {
            $this->components->warn('Dibatalkan.');

            return self::SUCCESS;
        }

        config(['database.connections.mysql_root' => array_merge(
            (array) config('database.connections.mysql'),
            ['database' => null],
        )]);

        DB::connection('mysql_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
        $this->components->info("Basis data `{$database}` dihapus.");

        return self::SUCCESS;
    }
}
