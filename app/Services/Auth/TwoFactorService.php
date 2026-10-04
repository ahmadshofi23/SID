<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * 2FA berbasis TOTP — FR-AUT-05.
 *
 * TOTP (bukan OTP SMS) dipilih sebagai bawaan karena tidak berbiaya per pesan,
 * tidak bergantung sinyal seluler di kantor desa, dan tidak rentan SIM swap.
 * Kode pemulihan disiapkan untuk kasus ponsel hilang/rusak — tanpa itu,
 * kehilangan ponsel berarti kehilangan akses Admin Desa secara permanen.
 */
final class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditLogger $audit,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function qrCodeSvg(User $user, #[SensitiveParameter] string $secret): string
    {
        $issuer = (string) config('app.name');

        $url = $this->google2fa->getQRCodeUrl(
            $issuer,
            (string) ($user->email ?? $user->phone ?? $user->name),
            $secret
        );

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(240, 1), new SvgImageBackEnd
        ));

        return $writer->writeString($url);
    }

    public function verify(#[SensitiveParameter] string $secret, string $code): bool
    {
        // Toleransi 1 jendela (±30 detik) untuk jam ponsel yang sedikit bergeser.
        return (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }

    /**
     * Aktifkan 2FA dan hasilkan kode pemulihan.
     *
     * @return list<string> kode pemulihan dalam BENTUK TERANG — hanya kali ini
     *                      ditampilkan; yang disimpan adalah hash-nya.
     */
    public function confirm(User $user, #[SensitiveParameter] string $secret): array
    {
        $plainCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = Str::upper(Str::random(5).'-'.Str::random(5));
            $plainCodes[] = $code;
            $hashedCodes[] = Hash::make($code);
        }

        $user->setTwoFactorSecret($secret);
        $user->setRecoveryCodes($hashedCodes);
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->audit->log(AuditLogger::ACTION_2FA_ENABLED, $user, actor: $user);

        return $plainCodes;
    }

    /**
     * Pakai kode pemulihan. Kode yang sudah dipakai LANGSUNG DIHAPUS
     * (sekali pakai) agar tidak dapat dipakai ulang oleh penyerang.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $stored = $user->recoveryCodes();

        foreach ($stored as $index => $hashed) {
            if (Hash::check($code, $hashed)) {
                unset($stored[$index]);
                $user->setRecoveryCodes(array_values($stored));
                $user->save();

                $this->audit->log('auth.recovery_code_used', $user, context: [
                    'remaining' => count($stored),
                ], actor: $user);

                return true;
            }
        }

        return false;
    }
}
