<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute Web
|--------------------------------------------------------------------------
| URL berbahasa Indonesia secara sengaja: perangkat desa dan warga melihat
| dan kadang mengetikkan URL ini (NFR-USA-01).
*/

Route::view('/', 'welcome')->name('home');

/* --------------------------- Tamu (belum masuk) ------------------------- */
Route::middleware('guest')->group(function (): void {
    Route::get('masuk', [LoginController::class, 'show'])->name('login');
    Route::post('masuk', [LoginController::class, 'store'])
        ->middleware('throttle:20,1');   // pagar kasar; jeda bertingkat di LoginThrottle

    Route::get('masuk/dua-faktor/{challenge}', [TwoFactorController::class, 'challenge'])
        ->name('two-factor.challenge');
    Route::post('masuk/dua-faktor/{challenge}', [TwoFactorController::class, 'verifyChallenge'])
        ->middleware('throttle:10,1')
        ->name('two-factor.verify');

    Route::get('lupa-sandi', [PasswordResetController::class, 'showRequestForm'])
        ->name('password.request');
    Route::post('lupa-sandi', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:10,60')
        ->name('password.email');

    Route::get('atur-sandi/{token}', [PasswordResetController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('atur-sandi', [PasswordResetController::class, 'resetPassword'])
        ->name('password.update');
});

/* ------------------------- Terotentikasi ------------------------------- */
Route::middleware('auth')->group(function (): void {
    Route::post('keluar', [LoginController::class, 'destroy'])->name('logout');

    // Pemasangan 2FA — harus dapat diakses walau EnforceTwoFactor aktif.
    Route::get('dua-faktor/pasang', [TwoFactorController::class, 'setup'])
        ->name('two-factor.setup');
    Route::post('dua-faktor/pasang', [TwoFactorController::class, 'confirm'])
        ->name('two-factor.confirm');

    Route::view('dashboard', 'dashboard')->name('dashboard');

    /*
     | Contoh penerapan izin granular. Modul sebenarnya dibangun di Sprint 2–3;
     | yang penting pada Sprint 1 adalah MEKANISMENYA sudah terpasang & teruji.
     */
    Route::get('kependudukan/penduduk', function () {
        return view('placeholder', ['title' => 'Data Penduduk', 'sprint' => 'Sprint 2']);
    })->middleware('izin:penduduk.lihat')->name('residents.index');

    Route::get('pengaturan/log-aktivitas', function () {
        return view('placeholder', ['title' => 'Log Aktivitas', 'sprint' => 'Sprint 7']);
    })->middleware('izin:audit.lihat')->name('audit.index');
});
