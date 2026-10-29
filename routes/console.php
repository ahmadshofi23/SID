<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Pekerjaan Terjadwal
|--------------------------------------------------------------------------
*/

use App\Services\Population\StatisticsService;
use Illuminate\Support\Facades\Schedule;

/*
 * Statistik disegarkan tiap jam (docs/05-model-data.md §4).
 *
 * Penyegaran langsung juga dipicu setelah impor massal dan setiap mutasi —
 * jadwal ini hanya jaring pengaman untuk perubahan yang lolos, misalnya
 * perbaikan data lewat SQL. withoutOverlapping() mencegah dua penyegaran
 * berjalan bersamaan dan saling menghapus baris.
 */
Schedule::call(fn (StatisticsService $statistics) => $statistics->refresh())
    ->hourly()
    ->name('segarkan-statistik-penduduk')
    ->withoutOverlapping();

/*
 * Berkas impor dihapus setelah 7 hari (R-20).
 *
 * Jeda 7 hari, bukan langsung: bila hasil impor dipersoalkan, berkas aslinya
 * masih dapat diperiksa dalam waktu yang wajar. Lebih lama dari itu, ia hanya
 * menjadi timbunan data pribadi tanpa guna.
 */
Schedule::command('sid:prune-import-files')
    ->dailyAt('02:30')
    ->name('bersihkan-berkas-impor')
    ->withoutOverlapping();

/*
 * Eskalasi pengaduan yang melewati SLA — FR-PGD-06.
 *
 * Pagi hari kerja, satu kali: notifikasi eskalasi yang datang tengah malam
 * akan terkubur di bawah notifikasi lain sebelum ada yang membukanya, dan
 * yang dua kali sehari hanya mengajarkan orang mengabaikannya.
 *
 * Perintah ini TIDAK menentukan apakah sebuah pengaduan terlambat — itu
 * selalu dihitung dari kueri. Lihat keterangan panjangnya pada
 * App\Console\Commands\EscalateComplaints.
 */
Schedule::command('sid:eskalasi-pengaduan')
    ->weekdays()
    ->dailyAt('07:30')
    ->timezone(config('sid.timezone'))
    ->name('eskalasi-pengaduan')
    ->withoutOverlapping();
