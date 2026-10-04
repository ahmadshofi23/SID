<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation ID per permintaan — NFR-MNT-06.
 *
 * Dipakai untuk menautkan satu pesan galat yang dilihat pengguna dengan baris
 * log, baris audit, dan laporan Sentry. Tanpa ini, laporan "sistem error"
 * dari perangkat desa hampir tidak dapat dilacak.
 */
final class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid7();

        $request->attributes->set('correlation_id', $id);
        Log::shareContext(['correlation_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $id);

        return $response;
    }
}
