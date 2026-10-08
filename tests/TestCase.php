<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VillageSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Larangan lazy loading DINYALAKAN saat pengujian, sama seperti di
         * lingkungan pengembangan dan staging.
         *
         * Dimatikan, uji tidak dapat menangkap relasi yang lupa di-eager-load —
         * dan kelas bug itu muncul sebagai HTTP 500 di hadapan petugas desa,
         * bukan di CI. Halaman Wilayah sempat rusak persis karena ini.
         */
        Model::preventLazyLoading(true);
    }

    /** Seeder minimum yang dibutuhkan hampir semua uji. */
    protected function seedFoundation(): void
    {
        $this->seed([
            ReferenceSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            VillageSeeder::class,
        ]);
    }

    /**
     * Ambil pesan galat validasi pertama untuk suatu field.
     *
     * Dengan driver sesi `array` (dipakai saat pengujian), ViewErrorBag
     * tersimpan dalam bentuk array bersarang, bukan objek.
     */
    protected function errorMessage(TestResponse $response, string $field): string
    {
        $errors = $response->getSession()->get('errors');

        if ($errors instanceof ViewErrorBag || $errors instanceof MessageBag) {
            return (string) $errors->first($field);
        }

        if (is_array($errors)) {
            $messages = $errors['default']['messages'][$field] ?? $errors[$field] ?? [];

            return (string) (is_array($messages) ? ($messages[0] ?? '') : $messages);
        }

        return '';
    }

    /** Buat pengguna dengan peran tertentu, opsional dibatasi wilayah. */
    protected function userWithRole(
        string $roleCode,
        ?Region $region = null,
        ?string $residentId = null,
        bool $withTwoFactor = true,
    ): User {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Uji '.$roleCode,
            'email' => $roleCode.'-'.uniqid().'@uji.test',
        ]);
        $user->password_hash = Hash::make('kata-sandi-uji-yang-panjang');
        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'resident_id' => $residentId,
            'email_verified_at' => now(),
        ])->save();

        $user->roles()->attach($role->id, ['region_id' => $region?->id]);
        $user->refresh();
        $user->load('roles');

        /*
         * Peran Admin Desa & Kepala Desa WAJIB 2FA (FR-AUT-05). Tanpa 2FA aktif,
         * middleware EnforceTwoFactor mengalihkan setiap permintaan ke halaman
         * pemasangan — persis seperti di produksi. Maka uji yang memakai peran
         * ini harus memulai dari keadaan 2FA sudah aktif, bila yang diuji bukan
         * alur pemasangan 2FA itu sendiri.
         */
        if ($withTwoFactor && $user->requiresTwoFactor()) {
            $user->setTwoFactorSecret(app(TwoFactorService::class)->generateSecret());
            $user->two_factor_confirmed_at = now();
            $user->save();
        }

        return $user->fresh(['roles.permissions', 'resident']);
    }
}
