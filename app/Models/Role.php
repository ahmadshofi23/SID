<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property-read Collection<int, Permission> $permissions
 */
final class Role extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** Kode peran — docs/03-srs.md §4.9 (tanpa Super Admin, KEP-001). */
    public const ADMIN_DESA = 'admin_desa';

    public const KEPALA_DESA = 'kepala_desa';

    public const KASI_PEMERINTAHAN = 'kasi_pemerintahan';

    public const KASI_KESEJAHTERAAN = 'kasi_kesejahteraan';

    public const KASI_PELAYANAN = 'kasi_pelayanan';

    public const KAUR_KEUANGAN = 'kaur_keuangan';

    public const KAUR_PERENCANAAN = 'kaur_perencanaan';

    public const KEPALA_DUSUN = 'kepala_dusun';

    public const OPERATOR = 'operator';

    public const WARGA = 'warga';

    public const PENGAWAS = 'pengawas';

    /**
     * Peran yang aksesnya DIBATASI WILAYAH.
     * Menambah peran ke daftar ini langsung mempersempit akses datanya.
     */
    public const REGION_LIMITED_ROLES = [self::KEPALA_DUSUN];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }
}
