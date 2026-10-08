<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Services\Population\RegionService;
use App\Services\Population\StatisticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class StatisticsController extends Controller
{
    public function index(
        Request $request,
        StatisticsService $statistics,
        RegionService $regions,
    ): Response {
        $regionId = $request->string('region_id')->toString() ?: null;

        /*
         * Penyamaran sel kecil DIMATIKAN untuk pengguna internal berizin
         * laporan, dan DINYALAKAN untuk sisanya.
         *
         * Alasannya: penyamaran ada untuk mencegah identifikasi ulang oleh
         * pihak luar (ADR-008). Petugas desa sudah berhak melihat data
         * orangnya satu per satu, jadi menyamarkan angka bagi mereka hanya
         * membuat laporan tidak dapat dipakai tanpa menambah perlindungan.
         */
        $suppress = ! $request->user()?->can('laporan.lihat');

        return Inertia::render('Kependudukan/Statistik/Index', [
            'stats' => $statistics->summary($regionId, $suppress),
            'regions' => $regions->rtOptions(),
            'regionId' => $regionId,
            'suppressed' => $suppress,
        ]);
    }

    /** Segarkan tabel ringkasan secara manual. */
    public function refresh(StatisticsService $statistics): RedirectResponse
    {
        $this->authorize('laporan.lihat');

        $rows = $statistics->refresh();

        return back()->with('status', "Statistik disegarkan ({$rows} kombinasi data).");
    }
}
