<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kebijakan masa sesi — FR-AUT-06, docs/08 §3.
 *
 * Dua batas berbeda untuk panel admin:
 *   - diam 30 menit   -> keluar (komputer kantor desa sering dipakai bersama)
 *   - absolut 12 jam   -> keluar walau aktif terus (membatasi dampak sesi dicuri)
 *
 * Warga tidak dikenai batas ini; sesinya 14 hari agar tidak perlu login
 * berulang saat mengurus surat dari ponsel.
 */
final class EnforceSessionPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Akun yang dinonaktifkan/dikunci saat sesi masih berjalan harus
        // langsung terputus, bukan menunggu sesi berakhir.
        if (! $user->isActive()) {
            return $this->endSession($request, 'Akun Anda tidak lagi aktif. Hubungi Admin Desa.');
        }

        if ($user->hasRole('warga')) {
            return $next($request);
        }

        $now = now()->getTimestamp();
        $started = (int) $request->session()->get('auth.started_at', $now);
        $lastSeen = (int) $request->session()->get('auth.last_seen_at', $now);

        $idleLimit = (int) config('sid.auth.admin_idle_minutes') * 60;
        $absoluteLimit = (int) config('sid.auth.admin_absolute_hours') * 3600;

        if ($now - $lastSeen > $idleLimit) {
            return $this->endSession(
                $request,
                'Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk kembali.'
            );
        }

        if ($now - $started > $absoluteLimit) {
            return $this->endSession(
                $request,
                'Sesi Anda telah mencapai batas waktu maksimum. Silakan masuk kembali.'
            );
        }

        $request->session()->put('auth.last_seen_at', $now);

        return $next($request);
    }

    private function endSession(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('warning', $message);
    }
}
