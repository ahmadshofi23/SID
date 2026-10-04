<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Identitas & sesi — docs/05-model-data.md §3.4
 *
 * Menggantikan migrasi `users` bawaan Laravel karena:
 *   - PK CHAR(36) UUID (bukan bigint) — tidak membocorkan jumlah pengguna di URL
 *   - login boleh pakai email ATAU nomor ponsel (FR-AUT-01)
 *   - ada status akun, 2FA, lockout, dan tautan ke data penduduk
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('resident_id', 36)->nullable()
                ->comment('Tautan akun warga ke data penduduk (dasar RULE-11)');
            $t->string('name', 150);
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('username', 60)->nullable();
            $t->string('password_hash', 255);
            $t->dateTime('email_verified_at')->nullable();
            $t->dateTime('phone_verified_at')->nullable();
            /*
             * Ukuran kolom mengikuti ENVELOPE enkripsi Laravel (Crypt), bukan
             * panjang nilai aslinya: rahasia TOTP 32 karakter menjadi ± 250
             * byte setelah dibungkus (IV + MAC + base64 + JSON). Kode
             * pemulihan (8 x 11 karakter) menjadi ± 500 byte. Diberi margin
             * agar rotasi kunci atau perubahan cipher tidak memotong data.
             */
            $t->binary('two_factor_secret', 1024)->nullable()->comment('Terenkripsi (APP_KEY)');
            $t->dateTime('two_factor_confirmed_at')->nullable();
            $t->binary('two_factor_recovery_codes', 4096)->nullable()->comment('Hash, terenkripsi');
            $t->enum('status', [
                'menunggu_verifikasi', 'aktif', 'nonaktif', 'terkunci',
            ])->default('menunggu_verifikasi');
            $t->enum('verification_method', ['ktp_upload', 'tatap_muka'])->nullable();
            $t->char('verified_by', 36)->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->unsignedSmallInteger('failed_login_count')->default(0);
            $t->dateTime('locked_until')->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable()->comment('Cukup untuk IPv6');
            $t->boolean('must_change_password')->default(false);
            $t->string('remember_token', 100)->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->dateTime('deleted_at')->nullable();

            // Keunikan bersyarat: email/telepon unik hanya untuk akun belum dihapus
            $t->string('email_active', 150)
                ->storedAs('IF(deleted_at IS NULL, email, NULL)');
            $t->string('phone_active', 30)
                ->storedAs('IF(deleted_at IS NULL, phone, NULL)');

            $t->unique('email_active', 'uq_users_email');
            $t->unique('phone_active', 'uq_users_phone');
            $t->index('resident_id', 'idx_users_resident');
            $t->index('status', 'idx_users_status');

            $t->foreign('resident_id', 'fk_users_resident')
                ->references('id')->on('residents')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE users
                ADD CONSTRAINT chk_users_contact
                CHECK (email IS NOT NULL OR phone IS NOT NULL)'
        );

        Schema::create('password_reset_tokens', function (Blueprint $t): void {
            $t->string('identifier', 150)->primary()->comment('Email atau no. ponsel');
            $t->string('token', 255);
            $t->dateTime('created_at')->useCurrent();
        });

        Schema::create('sessions', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->char('user_id', 36)->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
