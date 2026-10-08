<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AccessScope;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(
        AccessScope $scope,
        ResidentRepository $residents,
        FamilyRepository $families,
    ): Response {
        /*
         * Angka hanya dihitung bila pengguna memang berhak melihatnya.
         * Warga dan peran tanpa izin kependudukan mendapat `null`, dan kartu
         * KPI tidak ditampilkan sama sekali — bukan ditampilkan bernilai nol,
         * yang akan menyesatkan.
         *
         * Seluruh hitungan melewati repository, sehingga otomatis terbatas
         * pada wilayah/keluarga pengguna (docs/04-arsitektur.md §4.1).
         */
        $stats = $scope->can('penduduk.lihat') ? [
            'residents_active' => $residents->countActive(),
            'residents_total' => $residents->countAll(),
            'families' => $scope->can('keluarga.lihat') ? $families->countAll() : 0,
        ] : null;

        return Inertia::render('Dashboard', ['stats' => $stats]);
    }
}
