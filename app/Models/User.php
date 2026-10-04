<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string|null $resident_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $password_hash
 * @property string $status
 * @property int $failed_login_count
 * @property Carbon|null $locked_until
 * @property Carbon|null $two_factor_confirmed_at
 * @property mixed $two_factor_secret
 * @property mixed $two_factor_recovery_codes
 * @property-read Resident|null $resident
 * @property-read Collection<int, Role> $roles
 */
final class User extends Authenticatable implements AuthenticatableContract
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [
        'id', 'status', 'resident_id', 'email_active', 'phone_active',
        'failed_login_count', 'locked_until', 'verified_by', 'verified_at',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected $hidden = [
        'password_hash', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    public const STATUS_PENDING = 'menunggu_verifikasi';

    public const STATUS_ACTIVE = 'aktif';

    public const STATUS_DISABLED = 'nonaktif';

    public const STATUS_LOCKED = 'terkunci';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** Kolom kata sandi bernama password_hash, bukan password. */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('region_id');
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function hasRole(string $code): bool
    {
        return $this->roles->contains('code', $code);
    }

    public function hasPermission(string $code): bool
    {
        return $this->roles->contains(
            fn (Role $role) => $role->permissions->contains('code', $code)
        );
    }

    /**
     * Daftar `path` wilayah yang boleh diakses pengguna ini.
     *
     * null  = tidak dibatasi wilayah (Admin Desa, Kades, Kasi, dst.)
     * []    = dibatasi tetapi belum ada wilayah ditugaskan -> TIDAK MELIHAT APA PUN
     *
     * Mengembalikan [] dan bukan null untuk kasus kedua adalah keputusan sadar:
     * gagal secara tertutup (fail closed). Kepala Dusun yang belum ditugaskan
     * wilayah lebih baik tidak melihat apa pun daripada melihat seluruh desa.
     *
     * @return list<string>|null
     */
    public function regionPaths(): ?array
    {
        $this->loadMissing('roles');

        $limited = $this->roles->filter(
            fn (Role $role) => in_array($role->code, Role::REGION_LIMITED_ROLES, true)
        );

        if ($limited->isEmpty()) {
            return null;
        }

        $regionIds = $limited->pluck('pivot.region_id')->filter()->unique();

        if ($regionIds->isEmpty()) {
            return [];
        }

        /** @var list<string> $paths */
        $paths = Region::query()
            ->whereIn('id', $regionIds)
            ->pluck('path')
            ->all();

        return $paths;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED
            || ($this->locked_until !== null && $this->locked_until->isFuture());
    }

    /** 2FA wajib untuk peran tertentu (FR-AUT-05). */
    public function requiresTwoFactor(): bool
    {
        $required = (array) config('sid.auth.require_2fa_roles');

        return $this->roles->contains(fn (Role $r) => in_array($r->code, $required, true));
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Rahasia TOTP disimpan terenkripsi (APP_KEY), bukan PII_ENCRYPTION_KEY,
     * karena ini kredensial sistem — bukan data pribadi warga.
     */
    public function twoFactorSecret(): ?string
    {
        $raw = $this->two_factor_secret;

        if ($raw === null) {
            return null;
        }

        return Crypt::decryptString(
            is_resource($raw) ? (string) stream_get_contents($raw) : (string) $raw
        );
    }

    public function setTwoFactorSecret(?string $secret): void
    {
        $this->two_factor_secret = $secret === null
            ? null
            : Crypt::encryptString($secret);
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        $raw = $this->two_factor_recovery_codes;

        if ($raw === null) {
            return [];
        }

        $json = Crypt::decryptString(
            is_resource($raw) ? (string) stream_get_contents($raw) : (string) $raw
        );

        return json_decode($json, true) ?: [];
    }

    /** @param  list<string>  $codes */
    public function setRecoveryCodes(array $codes): void
    {
        $this->two_factor_recovery_codes = Crypt::encryptString(
            json_encode($codes, JSON_THROW_ON_ERROR)
        );
    }
}
