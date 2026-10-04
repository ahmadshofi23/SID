<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Kebijakan kata sandi — FR-AUT-02, mengikuti NIST SP 800-63B.
 *
 * Yang DIPERIKSA: panjang minimum dan apakah kata sandi muncul di kebocoran.
 * Yang TIDAK diperiksa: keharusan campuran huruf besar/angka/simbol, dan tidak
 * ada pemaksaan ganti berkala. Keduanya terbukti mendorong pola lemah yang
 * mudah diduga ("Password1!", "Januari2026") dan menambah beban petugas desa
 * tanpa menambah keamanan nyata.
 */
final class StrongPassword implements ValidationRule
{
    public function validate(
        string $attribute,
        #[SensitiveParameter] mixed $value,
        Closure $fail
    ): void {
        $error = $this->check($value);

        if ($error !== null) {
            $fail($error);
        }
    }

    /**
     * Periksa kata sandi, kembalikan pesan galat atau null bila lolos.
     *
     * Dipisah dari validate() agar aturan yang sama dapat dipakai di luar
     * validator Laravel — misalnya pada perintah artisan sid:create-admin —
     * tanpa perlu membungkusnya dengan closure tiruan.
     */
    public function check(#[SensitiveParameter] mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Kata sandi tidak valid.';
        }

        $min = (int) config('sid.auth.password_min_length');

        if (mb_strlen($value) < $min) {
            return "Kata sandi minimal {$min} karakter. Gunakan beberapa kata yang mudah Anda ingat, misalnya tiga kata yang tidak berhubungan.";
        }

        if ($this->isCompromised($value)) {
            return 'Kata sandi ini pernah muncul dalam kebocoran data di internet sehingga mudah ditebak. Gunakan kata sandi lain.';
        }

        return null;
    }

    /**
     * Pemeriksaan ke daftar kata sandi bocor memakai k-anonymity: hanya 5
     * karakter pertama hash SHA-1 yang dikirim, sehingga kata sandi itu
     * sendiri TIDAK PERNAH meninggalkan server.
     *
     * Gagal menghubungi layanan TIDAK memblokir pengguna — ketersediaan
     * layanan pihak ketiga tidak boleh menghentikan pelayanan desa.
     */
    private function isCompromised(#[SensitiveParameter] string $password): bool
    {
        if (config('app.env') === 'testing' || ! config('sid.auth.check_breached', true)) {
            return in_array(mb_strtolower($password), self::LOCAL_DENYLIST, true);
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::timeout(3)
                ->withHeaders(['Add-Padding' => 'true'])
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");

            if (! $response->successful()) {
                return in_array(mb_strtolower($password), self::LOCAL_DENYLIST, true);
            }

            foreach (explode("\n", $response->body()) as $line) {
                [$candidate] = explode(':', trim($line)) + [''];

                if ($candidate === $suffix) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return in_array(mb_strtolower($password), self::LOCAL_DENYLIST, true);
        }

        return false;
    }

    /** Cadangan lokal bila layanan daring tidak dapat dihubungi. */
    private const LOCAL_DENYLIST = [
        'password', 'password123', 'kataupdate', '1234567890', 'qwertyuiop',
        'adminadmin', 'administrator', 'desa123456', 'sistemdesa',
        'indonesia1', 'rahasia123', 'bismillah1', 'kepaladesa',
    ];
}
