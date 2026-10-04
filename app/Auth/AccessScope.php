<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;

/**
 * Lingkup akses efektif seorang pengguna — docs/04-arsitektur.md §4.1.
 *
 * Objek ini adalah SATU-SATUNYA sumber kebenaran untuk dua pembatasan yang,
 * tanpa Row-Level Security (MySQL, KEP-002), tidak punya jaring pengaman di
 * basis data:
 *
 *   - regionPaths : Kepala Dusun / RT hanya boleh melihat wilayahnya
 *   - familyId    : Warga hanya boleh melihat dirinya & anggota satu KK (RULE-11)
 *
 * Dibuat sebagai objek nilai tak-berubah agar tidak mungkin "dilonggarkan"
 * di tengah permintaan.
 */
final class AccessScope
{
    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $roles
     * @param  list<string>|null  $regionPaths  null = seluruh desa
     */
    private function __construct(
        public readonly ?string $userId,
        public readonly array $permissions,
        public readonly array $roles,
        public readonly ?array $regionPaths,
        public readonly ?string $residentId,
        public readonly ?string $familyId,
    ) {}

    public static function forUser(User $user): self
    {
        $user->loadMissing(['roles.permissions', 'resident']);

        $permissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('code'))
            ->unique()->values()->all();

        $roles = $user->roles->pluck('code')->values()->all();

        return new self(
            userId: $user->id,
            permissions: $permissions,
            roles: $roles,
            regionPaths: $user->regionPaths(),
            residentId: $user->resident_id,
            familyId: $user->resident?->family_id,
        );
    }

    /** Lingkup untuk pengunjung anonim: tidak punya akses apa pun. */
    public static function guest(): self
    {
        return new self(null, [], [], [], null, null);
    }

    /**
     * Lingkup sistem untuk pekerjaan terjadwal / impor massal.
     *
     * TIDAK BOLEH dipakai pada permintaan HTTP. Dipakai hanya oleh perintah
     * artisan dan job yang berjalan tanpa pengguna, dan setiap pemakaiannya
     * tetap tercatat di audit log dengan actor "sistem".
     */
    public static function system(): self
    {
        return new self('system', ['*'], ['sistem'], null, null, null);
    }

    public function can(string $permission): bool
    {
        return in_array('*', $this->permissions, true)
            || in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** Dibatasi wilayah tertentu (Kepala Dusun / RT)? */
    public function isRegionLimited(): bool
    {
        return $this->regionPaths !== null;
    }

    /** Hanya boleh melihat data keluarganya sendiri (Warga)? */
    public function isFamilyLimited(): bool
    {
        return $this->hasRole('warga');
    }
}
