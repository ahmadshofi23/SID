<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AccessScope;
use App\Services\Dashboard\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard — FR-LAP-01, FR-LAP-02, UC-17, docs/07 §5.4.
 *
 * Isinya disusun DashboardService; yang tersisa di sini hanyalah merender.
 * Pemisahan itu ada karena dashboard membaca dari lima repository sekaligus,
 * dan aturan "siapa boleh melihat angka apa" terlalu panjang untuk hidup di
 * sebuah controller — di sana ia akan ditambah sepotong demi sepotong setiap
 * kali ada kartu baru, sampai tidak ada lagi yang dapat membacanya utuh.
 *
 * Tanpa konstruktor untuk dependensi ber-AccessScope (KEP-009): DashboardService
 * membawa repository berlingkup, sehingga ia WAJIB lewat injeksi method.
 */
final class DashboardController extends Controller
{
    public function __invoke(AccessScope $scope, DashboardService $dashboard): Response
    {
        return Inertia::render('Dashboard', $dashboard->forScope($scope));
    }
}
