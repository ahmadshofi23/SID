<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Console\Command;
use PragmaRX\Google2FA\Google2FA;

/**
 * Tampilkan kode 2FA yang sedang berlaku — HANYA untuk pengembangan lokal.
 *
 * Peran Admin Desa dan Kepala Desa wajib verifikasi dua langkah (FR-AUT-05).
 * Saat pengembangan, itu berarti akun contoh tidak dapat dipakai sama sekali
 * tanpa memasang aplikasi autentikator lebih dulu — dan tanpa jalan keluar,
 * seluruh fitur yang hanya dimiliki Admin Desa (impor massal, pembukaan NIK,
 * pengelolaan pengguna) menjadi tidak dapat dicoba.
 *
 * Perintah ini menutup celah itu untuk pengembangan, TANPA melemahkan 2FA-nya
 * sendiri: ia hanya membaca rahasia yang sudah ada dan menghitung kode yang
 * sama dengan yang dihitung aplikasi autentikator.
 */
final class ShowTotpCode extends Command
{
    protected $signature = 'sid:totp {email : Email akun}';

    protected $description = 'Tampilkan kode verifikasi dua langkah yang sedang berlaku (lokal saja)';

    public function handle(Google2FA $google2fa, TwoFactorService $twoFactor): int
    {
        /*
         * Pagar mutlak. Di produksi, perintah ini akan memberi siapa pun yang
         * punya akses shell jalan pintas melewati 2FA seluruh akun — persis
         * hal yang 2FA ada untuk mencegahnya.
         */
        if (! app()->environment('local', 'testing')) {
            $this->components->error(
                'Perintah ini hanya tersedia di lingkungan lokal. '
                .'Di produksi, 2FA tidak boleh dapat dilewati dari shell.'
            );

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("Akun {$email} tidak ditemukan.");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->components->info(
                "{$email} belum mengaktifkan 2FA — masuk cukup dengan kata sandi."
            );

            return self::SUCCESS;
        }

        $secret = $user->twoFactorSecret();

        if ($secret === null) {
            $this->components->error('Rahasia 2FA tidak dapat dibaca.');

            return self::FAILURE;
        }

        $code = $google2fa->getCurrentOtp($secret);
        $secondsLeft = 30 - (int) (time() % 30);

        $this->newLine();
        $this->line("  Akun  : <options=bold>{$email}</>");
        $this->line("  Kode  : <fg=green;options=bold>{$code}</>");
        $this->line("  Berlaku {$secondsLeft} detik lagi.");
        $this->newLine();
        $this->line('  Ingin memakai aplikasi autentikator sungguhan? Masukkan');
        $this->line('  rahasia ini secara manual di Google Authenticator/Aegis:');
        $this->line("  <options=bold>{$secret}</>");
        $this->newLine();

        return self::SUCCESS;
    }
}
