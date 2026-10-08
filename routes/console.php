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
