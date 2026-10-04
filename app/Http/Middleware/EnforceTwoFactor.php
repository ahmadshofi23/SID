<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA WAJIB untuk peran berisiko tinggi — FR-AUT-05.
 *
 * Pengguna dengan peran Admin Desa / Kepala Desa yang belum mengaktifkan 2FA
 * dipaksa ke halaman pengaturan 2FA dan tidak dapat mengakses apa pun yang lain.
 * Tanpa pemaksaan ini, 2FA yang "tersedia tetapi opsional" dalam praktiknya
 * tidak pernah diaktifkan.
 */
final class EnforceTwoFactor
{
    /** Rute yang tetap boleh diakses agar pengguna dapat memasang 2FA. */
    private const ALLOWED = [
        'dua-faktor/*', 'keluar', 'profil/keamanan*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->requiresTwoFactor() || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        foreach (self::ALLOWED as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        return redirect()
            ->route('two-factor.setup')
            ->with('warning', 'Peran Anda mewajibkan verifikasi dua langkah. Aktifkan terlebih dahulu untuk melanjutkan.');
    }
}
