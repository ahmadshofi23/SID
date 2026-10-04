<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $identifier = $request->normalizedIdentifier();
        $ip = (string) $request->ip();

        if (($wait = $this->throttle->availableIn($identifier, $ip)) > 0) {
            throw ValidationException::withMessages([
                'identifier' => "Terlalu banyak percobaan masuk. Coba lagi dalam {$wait} detik.",
            ]);
        }

        $user = $this->findUser($request);

        /*
         * ANTI-ENUMERASI (FR-AUT-10).
         *
         * Satu pesan yang sama untuk SEMUA kegagalan: akun tidak ada, kata
         * sandi salah, atau akun belum terverifikasi. Pesan yang membedakan
         * ("email tidak terdaftar") akan mengubah formulir login menjadi alat
         * untuk memeriksa apakah seseorang warga desa ini — sebuah kebocoran
         * data pribadi tersendiri.
         *
         * Pengecualian disengaja: akun TERKUNCI diberi pesan berbeda. Tanpa
         * itu, pengguna sah akan terus mencoba dan memperpanjang penguncian.
         * Kebocoran informasinya kecil dan manfaat kebergunaannya besar.
         */
        if ($user !== null && $user->isLocked()) {
            $minutes = (int) ceil(now()->diffInMinutes($user->locked_until ?? now(), false));

            throw ValidationException::withMessages([
                'identifier' => $minutes > 0
                    ? "Akun Anda dikunci sementara karena terlalu banyak percobaan masuk yang gagal. Coba lagi dalam sekitar {$minutes} menit, atau hubungi Admin Desa."
                    : 'Akun Anda dikunci. Hubungi Admin Desa untuk membuka kembali.',
            ]);
        }

        $passwordValid = $user !== null
            && Hash::check((string) $request->input('password'), $user->getAuthPassword());

        if (! $passwordValid || ! $user->isActive()) {
            // Hash dummy agar waktu respons untuk akun yang tidak ada setara
            // dengan akun yang ada — mencegah enumerasi lewat pengukuran waktu.
            if ($user === null) {
                Hash::check((string) $request->input('password'), Hash::make(Str::random(32)));
            }

            $this->throttle->recordFailure($identifier, $ip, $user);

            throw ValidationException::withMessages([
                'identifier' => 'Email/nomor ponsel atau kata sandi tidak sesuai.',
            ]);
        }

        $this->throttle->clear($identifier, $ip, $user);

        // 2FA: jangan login dulu. Simpan tantangan berumur pendek di cache,
        // bukan di sesi, agar sesi belum terotentikasi sama sekali.
        if ($user->hasTwoFactorEnabled()) {
            $challenge = (string) Str::uuid7();

            Cache::put(
                "2fa:challenge:{$challenge}",
                ['user_id' => $user->id, 'remember' => (bool) $request->boolean('remember')],
                (int) config('sid.auth.two_factor_challenge_ttl')
            );

            return redirect()->route('two-factor.challenge', ['challenge' => $challenge]);
        }

        $this->completeLogin($request, $user, (bool) $request->boolean('remember'));

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $this->audit->log(AuditLogger::ACTION_LOGOUT, $user, actor: $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Anda telah keluar.');
    }

    private function findUser(LoginRequest $request): ?User
    {
        $identifier = $request->normalizedIdentifier();

        return User::query()
            ->with('roles.permissions')
            ->when(
                $request->identifierIsEmail(),
                fn ($q) => $q->where('email', $identifier),
                fn ($q) => $q->where('phone', $identifier),
            )
            ->first();
    }

    public function completeLogin(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);

        // Regenerasi ID sesi saat naik hak — mencegah session fixation.
        $request->session()->regenerate();
        $request->session()->put('auth.started_at', now()->getTimestamp());
        $request->session()->put('auth.last_seen_at', now()->getTimestamp());

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->log(AuditLogger::ACTION_LOGIN, $user, actor: $user);
    }
}
