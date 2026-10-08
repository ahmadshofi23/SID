<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
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
    protected $signature = 'sid:totp
                            {email? : Email akun yang 2FA-nya sudah aktif}
                            {--secret= : Hitung kode dari rahasia yang tampil di halaman pemasangan}';

    protected $description = 'Tampilkan kode verifikasi dua langkah yang sedang berlaku (lokal saja)';

    public function handle(Google2FA $google2fa): int
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

        /*
         * Mode rahasia langsung: untuk akun yang BARU memasang 2FA.
         *
         * Pada halaman pemasangan, rahasianya belum tersimpan di basis data —
         * ia masih berada di sesi, menunggu dikonfirmasi dengan kode pertama.
         * Tanpa mode ini, seseorang yang tidak punya aplikasi autentikator
         * tidak akan pernah bisa menyelesaikan pemasangan.
         */
        if (($rawSecret = $this->option('secret')) !== null) {
            return $this->printCode('(rahasia dari halaman pemasangan)', (string) $rawSecret, $google2fa);
        }

        $email = (string) $this->argument('email');

        if ($email === '') {
            $this->components->error(
                'Sebutkan email akun, atau pakai --secret=... untuk kode dari halaman pemasangan.'
            );

            return self::FAILURE;
        }

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

        return $this->printCode($email, $secret, $google2fa);
    }

    private function printCode(string $label, string $secret, Google2FA $google2fa): int
    {
        try {
            $code = $google2fa->getCurrentOtp($secret);
        } catch (\Throwable $e) {
            $this->components->error('Rahasia tidak valid: '.$e->getMessage());

            return self::FAILURE;
        }

        $secondsLeft = 30 - (int) (time() % 30);

        $this->newLine();
        $this->line("  Akun  : <options=bold>{$label}</>");
        $this->line("  Kode  : <fg=green;options=bold>{$code}</>");
        $this->line("  Berlaku <options=bold>{$secondsLeft} detik</> lagi.");
        $this->newLine();
        $this->line('  Ingin memakai aplikasi autentikator sungguhan? Masukkan');
        $this->line('  rahasia ini secara manual di Google Authenticator/Aegis:');
        $this->line("  <options=bold>{$secret}</>");
        $this->newLine();

        return self::SUCCESS;
    }
}
