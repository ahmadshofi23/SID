<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureVerifiedCitizen;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Providers\SidServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Berlaku untuk SEMUA permintaan, termasuk portal publik.
        $middleware->append(SecurityHeaders::class);
        $middleware->prepend(AssignCorrelationId::class);

        $middleware->web(append: [
            EnforceSessionPolicy::class,
            EnforceTwoFactor::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'izin' => EnsurePermission::class,
            // Pintu portal warga: berperan warga, akun aktif, tertaut ke
            // satu data penduduk (UC-05 prakondisi, RULE-11).
            'warga' => EnsureVerifiedCitizen::class,
        ]);

        // Rute masuk dialihkan ke /masuk, bukan /login.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withProviders([
        SidServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Pengguna hanya melihat pesan umum + correlation id; detail teknis
         * hanya masuk log (NFR-SEC, docs/08 §6 A05). Pesan galat yang
         * membocorkan nama tabel atau jejak kode adalah bantuan gratis bagi
         * penyerang.
         */
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            /*
             * PENOLAKAN HARUS TERLIHAT PETUGAS.
             *
             * Inertia menangani balasan 403/409 atas sebuah POST dengan
             * TIDAK MENGUBAH APA PUN di layar: tombol ditekan, dan tidak
             * terjadi apa-apa. Petugas desa menyimpulkan sistemnya rusak,
             * lalu menekannya berulang kali.
             *
             * Ditemukan saat menelusuri alur persetujuan surat di peramban —
             * seluruh pengujian otomatis tetap hijau, karena uji memeriksa
             * KODE STATUS, dan kode statusnya memang benar.
             *
             * Karena itu penolakan pada permintaan Inertia dialihkan kembali
             * ke halaman asal dengan pesan kesalahan yang tampil sebagai
             * notifikasi (lihat FlashMessages.vue).
             */
            if (
                in_array($status, [403, 409], true)
                && $request->header('X-Inertia')
                && ! $request->isMethod('GET')
            ) {
                return back()->with('error', $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Tindakan ini tidak dapat dilakukan.');
            }

            if (! app()->isProduction() || $request->expectsJson()) {
                return $response;
            }

            if ($status === 500) {
                return response()->view('errors.500', [
                    'correlationId' => $request->attributes->get('correlation_id'),
                ], 500);
            }

            return $response;
        });
    })->create();
