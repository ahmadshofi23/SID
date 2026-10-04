<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Reset kata sandi — FR-AUT-04, FR-AUT-10.
 */
final class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function showRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * SELALU mengembalikan pesan sukses yang sama, baik akun ada maupun tidak
     * (FR-AUT-10, docs/06 §3). Membedakan respons akan mengubah halaman ini
     * menjadi alat untuk memeriksa apakah suatu email/nomor terdaftar.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:150'],
        ], [
            'identifier.required' => 'Masukkan email atau nomor ponsel Anda.',
        ]);

        $identifier = mb_strtolower(trim((string) $validated['identifier']));
        $key = 'reset:'.sha1($identifier);

        // 3 permintaan per jam per identifier (docs/06 §3).
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'identifier' => 'Terlalu banyak permintaan. Coba lagi dalam satu jam.',
            ]);
        }

        RateLimiter::hit($key, 3600);

        $user = User::query()
            ->where(fn ($q) => $q->where('email', $identifier)->orWhere('phone', $identifier))
            ->first();

        if ($user !== null && $user->isActive()) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['identifier' => $identifier],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            // Sprint 1: tautan dicatat ke log (MAIL_MAILER=log).
            // Sprint 6 menggantinya dengan email/WhatsApp sebenarnya.
            Log::info('Tautan reset kata sandi dibuat', [
                'user_id' => $user->id,
                'url' => route('password.reset', ['token' => $token, 'identifier' => $identifier]),
                'kedaluwarsa_menit' => config('sid.auth.reset_token_minutes'),
            ]);

            $this->audit->log('auth.password_reset_requested', $user, actor: $user);
        }

        return back()->with(
            'status',
            'Bila email atau nomor ponsel tersebut terdaftar, kami telah mengirimkan tautan untuk mengatur ulang kata sandi. Periksa pesan masuk Anda.'
        );
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'identifier' => (string) $request->query('identifier', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'identifier' => ['required', 'string', 'max:150'],
            'password' => ['required', 'confirmed', new StrongPassword],
        ], [
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
        ]);

        $identifier = mb_strtolower(trim((string) $validated['identifier']));

        $record = DB::table('password_reset_tokens')
            ->where('identifier', $identifier)
            ->first();

        $expiryMinutes = (int) config('sid.auth.reset_token_minutes');

        $isValid = $record !== null
            && Hash::check((string) $validated['token'], $record->token)
            && now()->diffInMinutes($record->created_at) <= $expiryMinutes;

        if (! $isValid) {
            throw ValidationException::withMessages([
                'token' => 'Tautan ini tidak berlaku atau sudah kedaluwarsa. Ajukan permintaan baru.',
            ]);
        }

        $user = User::query()
            ->where(fn ($q) => $q->where('email', $identifier)->orWhere('phone', $identifier))
            ->firstOrFail();

        $user->forceFill([
            'password_hash' => Hash::make((string) $validated['password']),
            'must_change_password' => false,
            'failed_login_count' => 0,
            'locked_until' => null,
            'remember_token' => null,
        ])->save();

        // Token sekali pakai.
        DB::table('password_reset_tokens')->where('identifier', $identifier)->delete();

        /*
         * Cabut SEMUA sesi lain. Bila kata sandi di-reset karena diduga
         * disalahgunakan, membiarkan sesi lama tetap aktif membuat reset
         * menjadi tidak bermakna.
         */
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->audit->log('auth.password_reset_completed', $user, actor: $user);

        return redirect()->route('login')->with(
            'status',
            'Kata sandi berhasil diubah. Silakan masuk dengan kata sandi baru Anda.'
        );
    }
}
