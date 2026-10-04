<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AccessScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemeriksaan izin granular di sisi server — NFR-SEC-02.
 *
 * Menyembunyikan menu di UI adalah kenyamanan, BUKAN kontrol keamanan
 * (docs/07-ui-ux.md §2.4). Lapis inilah kontrol sebenarnya.
 *
 * Pemakaian: ->middleware('izin:penduduk.lihat')
 */
final class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $scope = app(AccessScope::class);

        foreach ($permissions as $permission) {
            if ($scope->can($permission)) {
                return $next($request);
            }
        }

        abort(403, 'Anda tidak memiliki hak akses untuk tindakan ini. Hubungi Admin Desa bila memerlukan penambahan hak akses.');
    }
}
