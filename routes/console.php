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
