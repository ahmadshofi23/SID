<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /*
     * AuthorizesRequests menyediakan $this->authorize('izin.kode').
     *
     * Pemeriksaannya berujung pada Gate::before di SidServiceProvider, yang
     * membaca izin efektif dari AccessScope. Satu titik penegakan — bukan
     * logika izin yang tersebar di tiap kontroler (docs/04 §6).
     */
    use AuthorizesRequests;
}
