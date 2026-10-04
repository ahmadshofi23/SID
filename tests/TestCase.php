<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VillageSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Lazy loading dimatikan di produksi; di pengujian dibiarkan agar
        // uji tidak gagal karena alasan yang tidak berkaitan dengan perilaku.
        Model::preventLazyLoading(false);
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

    /** Buat pengguna dengan peran tertentu, opsional dibatasi wilayah. */
    protected function userWithRole(
        string $roleCode,
        ?Region $region = null,
        ?string $residentId = null,
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

        return $user->fresh(['roles.permissions', 'resident']);
    }
}
