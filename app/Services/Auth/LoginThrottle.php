<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Pembatasan percobaan login — FR-AUT-03, docs/08 §3.
 *
 * Dua mekanisme berbeda, sengaja dipisah:
 *
 *  1. JEDA BERTINGKAT per (IP + identifier) — melawan penebakan kata sandi
 *     satu akun. Tidak mengunci akun, hanya memperlambat.
 *
 *  2. PENGUNCIAN AKUN setelah 10 gagal — melawan serangan gigih.
 *
 * Mengapa dipisah: bila hanya ada penguncian akun, penyerang dapat mengunci
 * akun Kepala Desa dengan sengaja memasukkan kata sandi salah (denial of
 * service). Jeda bertingkat membuat serangan itu tidak praktis lebih dulu.
 */
final class LoginThrottle
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Detik jeda setelah n percobaan gagal: 0,0,0,0,1,2,4,8,16,30,30... */
    public function delaySeconds(int $attempts): int
    {
        $threshold = (int) config('sid.auth.throttle_after_attempts');

        if ($attempts < $threshold) {
            return 0;
        }

        return min(30, 2 ** ($attempts - $threshold));
    }

    public function key(string $identifier, string $ip): string
    {
        return 'login:'.sha1(mb_strtolower($identifier).'|'.$ip);
    }

    /** Sisa detik sebelum boleh mencoba lagi (0 = boleh sekarang). */
    public function availableIn(string $identifier, string $ip): int
    {
        $key = $this->key($identifier, $ip);
        $attempts = RateLimiter::attempts($key);
        $delay = $this->delaySeconds($attempts);

        if ($delay === 0) {
            return 0;
        }

        return RateLimiter::tooManyAttempts($key.':delay', 1)
            ? RateLimiter::availableIn($key.':delay')
            : 0;
    }

    public function recordFailure(string $identifier, string $ip, ?User $user): void
    {
        $key = $this->key($identifier, $ip);

        RateLimiter::hit($key, 900); // jendela 15 menit
        $attempts = RateLimiter::attempts($key);

        if (($delay = $this->delaySeconds($attempts)) > 0) {
            RateLimiter::hit($key.':delay', $delay);
        }

        if ($user === null) {
            // Identifier tidak dikenal. TIDAK dicatat dengan identifier aslinya
            // agar audit log tidak menjadi daftar email/ponsel yang dicoba.
            $this->audit->log(AuditLogger::ACTION_LOGIN_FAILED, context: [
                'reason' => 'identifier_tidak_dikenal',
            ]);

            return;
        }

        $user->increment('failed_login_count');
        $user->refresh();

        $max = (int) config('sid.auth.max_login_attempts');

        if ($user->failed_login_count >= $max) {
            $minutes = (int) config('sid.auth.lockout_minutes');

            $user->forceFill([
                'locked_until' => now()->addMinutes($minutes),
                'failed_login_count' => 0,
            ])->save();

            $this->audit->log(AuditLogger::ACTION_LOCKED, $user, context: [
                'locked_minutes' => $minutes,
                'attempts' => $max,
            ]);

            return;
        }

        $this->audit->log(AuditLogger::ACTION_LOGIN_FAILED, $user, context: [
            'reason' => 'kata_sandi_salah',
            'attempt' => $user->failed_login_count,
        ]);
    }

    public function clear(string $identifier, string $ip, User $user): void
    {
        $key = $this->key($identifier, $ip);

        RateLimiter::clear($key);
        RateLimiter::clear($key.':delay');

        if ($user->failed_login_count !== 0 || $user->locked_until !== null) {
            $user->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
        }
    }
}
