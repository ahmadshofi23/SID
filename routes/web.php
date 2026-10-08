<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Population\FamilyController;
use App\Http\Controllers\Population\RegionController;
use App\Http\Controllers\Population\ResidentController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    /* ---------------------- Kependudukan (Sprint 2) --------------------- */
    Route::prefix('kependudukan')->group(function (): void {

        // Penduduk
        Route::get('penduduk', [ResidentController::class, 'index'])
            ->middleware('izin:penduduk.lihat')->name('penduduk.index');
        Route::get('penduduk/tambah', [ResidentController::class, 'create'])
            ->middleware('izin:penduduk.tambah')->name('penduduk.create');
        Route::post('penduduk', [ResidentController::class, 'store'])
            ->middleware('izin:penduduk.tambah')->name('penduduk.store');
        Route::get('penduduk/cari', [ResidentController::class, 'suggest'])
            ->middleware('izin:penduduk.lihat')->name('penduduk.suggest');
        Route::get('penduduk/{id}', [ResidentController::class, 'show'])
            ->middleware('izin:penduduk.lihat')->name('penduduk.show');
        Route::get('penduduk/{id}/ubah', [ResidentController::class, 'edit'])
            ->middleware('izin:penduduk.ubah')->name('penduduk.edit');
        Route::put('penduduk/{id}', [ResidentController::class, 'update'])
            ->middleware('izin:penduduk.ubah')->name('penduduk.update');
        Route::delete('penduduk/{id}', [ResidentController::class, 'destroy'])
            ->middleware('izin:penduduk.hapus')->name('penduduk.destroy');

        // Pembukaan NIK penuh — izin terpisah, selalu tercatat (RULE-07)
        Route::post('penduduk/{id}/nik', [ResidentController::class, 'revealNik'])
            ->middleware('izin:data_pribadi.lihat_penuh')->name('penduduk.reveal-nik');

        // Kartu keluarga
        Route::get('keluarga', [FamilyController::class, 'index'])
            ->middleware('izin:keluarga.lihat')->name('keluarga.index');
        Route::get('keluarga/tambah', [FamilyController::class, 'create'])
            ->middleware('izin:keluarga.tambah')->name('keluarga.create');
        Route::post('keluarga', [FamilyController::class, 'store'])
            ->middleware('izin:keluarga.tambah')->name('keluarga.store');
        Route::get('keluarga/{id}', [FamilyController::class, 'show'])
            ->middleware('izin:keluarga.lihat')->name('keluarga.show');
        Route::get('keluarga/{id}/ubah', [FamilyController::class, 'edit'])
            ->middleware('izin:keluarga.ubah')->name('keluarga.edit');
        Route::put('keluarga/{id}', [FamilyController::class, 'update'])
            ->middleware('izin:keluarga.ubah')->name('keluarga.update');
        Route::delete('keluarga/{id}', [FamilyController::class, 'destroy'])
            ->middleware('izin:keluarga.hapus')->name('keluarga.destroy');
        Route::post('keluarga/{id}/pindah-anggota', [FamilyController::class, 'moveMember'])
            ->middleware('izin:keluarga.ubah')->name('keluarga.move-member');
        Route::post('keluarga/{id}/nomor-kk', [FamilyController::class, 'revealKkNumber'])
            ->middleware('izin:data_pribadi.lihat_penuh')->name('keluarga.reveal-kk');

        // Wilayah
        Route::get('wilayah', [RegionController::class, 'index'])
            ->middleware('izin:wilayah.lihat')->name('wilayah.index');
        Route::get('wilayah/tambah', [RegionController::class, 'create'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.create');
        Route::post('wilayah', [RegionController::class, 'store'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.store');
        Route::get('wilayah/{region}/ubah', [RegionController::class, 'edit'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.edit');
        Route::put('wilayah/{region}', [RegionController::class, 'update'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.update');
        Route::delete('wilayah/{region}', [RegionController::class, 'destroy'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.destroy');
    });

    Route::get('pengaturan/log-aktivitas', fn () => Inertia::render('Placeholder', [
        'title' => 'Log Aktivitas',
        'sprint' => 'Sprint 7',
    ]))->middleware('izin:audit.lihat')->name('audit.index');
});
