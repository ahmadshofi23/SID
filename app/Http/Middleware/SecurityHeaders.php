<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan — NFR-SEC-07, docs/08 §6.
 *
 * Dipasang SEJAK SPRINT 1 secara sengaja: menambahkan CSP setelah aplikasi
 * selesai jauh lebih sulit karena skrip inline sudah tersebar di banyak
 * halaman. Lebih murah menanggung batasannya sejak awal.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = implode('; ', $this->contentSecurityPolicy());

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(self), camera=(self), microphone=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }

    /**
     * Content Security Policy.
     *
     * Produksi memakai kebijakan ketat. Saat pengembangan, server Vite berjalan
     * di porta terpisah dan memuat modul serta WebSocket dari sana — tanpa
     * pelonggaran ini, panel tidak dapat dimuat sama sekali saat `npm run dev`.
     *
     * Pelonggaran DIBATASI pada lingkungan lokal. Bila APP_ENV bukan `local`,
     * kebijakan ketat berlaku penuh, termasuk di staging — sehingga pelanggaran
     * CSP tertangkap sebelum produksi, bukan sesudahnya.
     *
     * @return list<string>
     */
    private function contentSecurityPolicy(): array
    {
        $policy = [
            "default-src 'self'",
            "img-src 'self' data: blob:",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self' data:",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        if (app()->environment('local')) {
            return [
                ...$policy,
                "script-src 'self' 'unsafe-eval' http://localhost:5173 http://127.0.0.1:5173",
                "connect-src 'self' ws://localhost:5173 ws://127.0.0.1:5173 http://localhost:5173 http://127.0.0.1:5173",
            ];
        }

        return [...$policy, "script-src 'self'", "connect-src 'self'"];
    }
}
