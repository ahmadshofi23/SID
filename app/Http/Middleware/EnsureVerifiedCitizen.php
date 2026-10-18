<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pintu portal warga — UC-05 prakondisi, RULE-11.
 *
 * TIGA SYARAT, dan ketiganya diperiksa di sini supaya tidak ada satu pun
 * layar `/warga/*` yang perlu mengingatnya sendiri:
 *
 *   1. Berperan `warga`. Petugas yang membuka /warga tidak sedang mengurus
 *      suratnya sendiri — ia punya layar loket, dan AccessScope-nya jauh
 *      lebih luas daripada yang diasumsikan layar ini.
 *   2. Akun berstatus aktif. Status lain sudah dihalangi LoginController,
 *      tetapi akun yang dinonaktifkan DI TENGAH sesi masih memegang sesi
 *      yang hidup sampai ia kedaluwarsa.
 *   3. Tertaut ke satu data penduduk. Tanpa `resident_id`, AccessScope warga
 *      tidak membatasi apa pun ke keluarga mana pun — dan layar yang
 *      menampilkan "data keluarga saya" akan kosong atau, lebih buruk,
 *      bergantung pada penyaringan yang tidak pernah terjadi.
 *
 * Yang GAGAL diarahkan ke halaman yang dapat ditindaklanjuti, bukan 403
 * telanjang: pemiliknya warga, bukan penyerang, dan ia perlu tahu harus ke
 * mana (KEP-012 — penolakan izin wajib terlihat dan dapat dipahami).
 */
final class EnsureVerifiedCitizen
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if (! $user->hasRole(Role::WARGA)) {
            /*
             * Petugas yang tersesat diarahkan ke dasbornya, bukan ditolak:
             * ini bukan upaya menembus, melainkan tautan yang salah diklik.
             */
            return redirect()->route('dashboard');
        }

        if (! $user->isActive() || $user->resident_id === null) {
            return redirect()
                ->route('warga.belum-aktif')
                ->with('warning', 'Akun Anda belum dapat dipakai untuk layanan mandiri.');
        }

        return $next($request);
    }
}
