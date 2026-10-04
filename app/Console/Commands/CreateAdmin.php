<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Membuat akun Admin Desa pertama — docs/08 §3, checklist pra-go-live.
 *
 * Sengaja interaktif dan tanpa kata sandi bawaan: akun bawaan dengan kata
 * sandi yang dapat ditebak adalah salah satu penyebab pembobolan paling
 * umum pada sistem pemerintahan.
 */
final class CreateAdmin extends Command
{
    protected $signature = 'sid:create-admin {--email=} {--name=}';

    protected $description = 'Membuat akun Admin Desa pertama (interaktif, tanpa kata sandi bawaan)';

    public function handle(AuditLogger $audit): int
    {
        $name = (string) ($this->option('name') ?: text(
            label: 'Nama lengkap',
            placeholder: 'Siti Rohmah',
            required: true,
        ));

        $email = (string) ($this->option('email') ?: text(
            label: 'Email',
            placeholder: 'sekdes@namadesa.desa.id',
            required: true,
        ));

        $validator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'email', 'max:150', 'unique:users,email']],
            ['email.unique' => 'Email tersebut sudah dipakai akun lain.']
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $plain = password(
            label: 'Kata sandi',
            hint: 'Minimal '.config('sid.auth.password_min_length')
                .' karakter. Gunakan beberapa kata yang mudah diingat.',
            required: true,
            validate: fn (string $value): ?string => (new StrongPassword)->check($value),
        );

        if (password(label: 'Ulangi kata sandi', required: true) !== $plain) {
            $this->components->error('Konfirmasi kata sandi tidak sama.');

            return self::FAILURE;
        }

        $role = Role::query()->where('code', Role::ADMIN_DESA)->first();

        if ($role === null) {
            $this->components->error(
                'Peran admin_desa belum ada. Jalankan terlebih dahulu: php artisan db:seed'
            );

            return self::FAILURE;
        }

        $user = new User;
        $user->fill(['name' => $name, 'email' => $email]);
        $user->password_hash = Hash::make($plain);
        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ])->save();

        $user->roles()->attach($role->id);

        $audit->log('user.admin_created', $user, context: [
            'created_via' => 'artisan',
        ], actor: $user);

        $this->newLine();
        $this->components->info("Akun Admin Desa dibuat: {$email}");
        $this->components->warn(
            'Peran ini MEWAJIBKAN verifikasi dua langkah. Pada login pertama, '
            .'Anda akan diminta memasang aplikasi autentikator.'
        );

        return self::SUCCESS;
    }
}
