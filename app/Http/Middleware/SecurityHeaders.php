<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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
        /*
         * Nonce dibangkitkan SEBELUM permintaan diproses, karena tampilan yang
         * dirender di dalam $next sudah harus dapat membacanya. Vite::useCspNonce()
         * menyimpannya sehingga tag hasil @vite pun ikut menyandangnya.
         */
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $csp = implode('; ', $this->contentSecurityPolicy($nonce));

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
     * Skrip sebaris diizinkan HANYA lewat nonce per permintaan, bukan
     * 'unsafe-inline'. Nonce yang berganti setiap permintaan tidak dapat
     * ditebak penyerang, sehingga skrip yang disuntikkan tetap diblokir —
     * sementara skrip tema di <head> (pencegah kedipan putih pada mode gelap)
     * tetap boleh berjalan.
     *
     * @return list<string>
     */
    private function contentSecurityPolicy(string $nonce): array
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
            // Vite dev server menyajikan CSS dan berkas font langsung dari
            // originnya sendiri, jadi style-src/font-src bawaan ('self' saja)
            // perlu diganti — bukan ditambah: pada CSP, direktif pertama yang
            // menang, sehingga entri duplikat di belakang akan diabaikan.
            $policy = array_values(array_filter(
                $policy,
                static fn (string $directive): bool => ! str_starts_with($directive, 'style-src ')
                    && ! str_starts_with($directive, 'font-src '),
            ));

            return [
                ...$policy,
                "style-src 'self' 'unsafe-inline' http://localhost:5173 http://127.0.0.1:5173",
                "font-src 'self' data: http://localhost:5173 http://127.0.0.1:5173",
                "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' http://localhost:5173 http://127.0.0.1:5173",
                "connect-src 'self' ws://localhost:5173 ws://127.0.0.1:5173 http://localhost:5173 http://127.0.0.1:5173",
            ];
        }

        return [...$policy, "script-src 'self' 'nonce-{$nonce}'", "connect-src 'self'"];
    }
}
