<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly LoginController $login,
    ) {}

    /** Tahap kedua login untuk akun yang sudah mengaktifkan 2FA. */
    public function challenge(string $challenge): View|RedirectResponse
    {
        if (! Cache::has("2fa:challenge:{$challenge}")) {
            return redirect()->route('login')->withErrors([
                'identifier' => 'Sesi verifikasi telah berakhir. Silakan masuk kembali.',
            ]);
        }

        return view('auth.two-factor-challenge', ['challenge' => $challenge]);
    }

    public function verifyChallenge(Request $request, string $challenge): RedirectResponse
    {
        $data = Cache::get("2fa:challenge:{$challenge}");

        if ($data === null) {
            return redirect()->route('login')->withErrors([
                'identifier' => 'Sesi verifikasi telah berakhir. Silakan masuk kembali.',
            ]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
        ], [
            'code.required' => 'Masukkan kode 6 angka dari aplikasi autentikator Anda.',
        ]);

        /** @var User $user */
        $user = User::query()->with('roles.permissions')->findOrFail($data['user_id']);
        $code = preg_replace('/\s+/', '', (string) $validated['code']) ?? '';

        $secret = $user->twoFactorSecret();
        $isValid = $secret !== null && $this->twoFactor->verify($secret, $code);

        if (! $isValid) {
            $isValid = $this->twoFactor->consumeRecoveryCode($user, strtoupper($code));
        }

        if (! $isValid) {
            throw ValidationException::withMessages([
                'code' => 'Kode verifikasi tidak sesuai. Pastikan jam di ponsel Anda tepat, lalu coba kode terbaru.',
            ]);
        }

        Cache::forget("2fa:challenge:{$challenge}");
        $this->login->completeLogin($request, $user, (bool) ($data['remember'] ?? false));

        return redirect()->intended(route('dashboard'));
    }

    /** Pemasangan 2FA (wajib untuk Admin Desa & Kepala Desa). */
    public function setup(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return view('auth.two-factor-enabled');
        }

        $secret = $request->session()->get('2fa.pending_secret')
            ?? $this->twoFactor->generateSecret();

        $request->session()->put('2fa.pending_secret', $secret);

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'qrCode' => $this->twoFactor->qrCodeSvg($user, $secret),
        ]);
    }

    public function confirm(Request $request): RedirectResponse|View
    {
        /** @var User $user */
        $user = $request->user();
        $secret = (string) $request->session()->get('2fa.pending_secret');

        if ($secret === '') {
            return redirect()->route('two-factor.setup');
        }

        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $code = preg_replace('/\s+/', '', (string) $validated['code']) ?? '';

        if (! $this->twoFactor->verify($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => 'Kode tidak sesuai. Pastikan Anda memasukkan kode terbaru dari aplikasi autentikator.',
            ]);
        }

        $recoveryCodes = $this->twoFactor->confirm($user, $secret);
        $request->session()->forget('2fa.pending_secret');

        // Kode pemulihan ditampilkan SATU KALI — setelahnya hanya hash yang
        // tersimpan, sehingga tidak dapat ditampilkan ulang.
        return view('auth.two-factor-recovery-codes', ['codes' => $recoveryCodes]);
    }
}
