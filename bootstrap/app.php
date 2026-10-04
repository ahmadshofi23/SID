<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsurePermission;
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
        ]);

        $middleware->alias([
            'izin' => EnsurePermission::class,
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
            if (! app()->isProduction() || $request->expectsJson()) {
                return $response;
            }

            if ($response->getStatusCode() === 500) {
                return response()->view('errors.500', [
                    'correlationId' => $request->attributes->get('correlation_id'),
                ], 500);
            }

            return $response;
        });
    })->create();
