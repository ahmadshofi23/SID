<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedPii;
use App\Support\Pii\Nik;
use App\Support\Pii\PiiCipher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Kartu Keluarga.
 *
 * TABEL DIJAGA: akses hanya melalui App\Repositories\FamilyRepository
 * (config/sid.php guarded_tables).
 */
/**
 * @property string $id
 * @property string|null $kk_number_enc
 * @property string $kk_number_hash
 * @property string $kk_number_masked
 * @property string|null $region_id
 * @property string $address
 * @property Carbon|null $deleted_at
 * @property-read Region|null $region
 * @property-read Collection<int, Resident> $members
 */
final class Family extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'kk_hash_active'];

    /** Kolom generated: dihitung MySQL, jangan pernah ditulis aplikasi. */
    /** @return list<string> */
    public function getGeneratedColumns(): array
    {
        return ['kk_hash_active'];
    }

    protected function casts(): array
    {
        return [
            'kk_number_enc' => EncryptedPii::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** Set nomor KK: enkripsi + hash + masking sekaligus (ADR-004). */
    public function setKkNumber(string $kkNumber): void
    {
        $normalized = Nik::normalize($kkNumber);

        $this->kk_number_enc = $normalized;
        $this->kk_number_hash = app(PiiCipher::class)->hash($normalized);
        $this->kk_number_masked = Nik::mask($normalized);
    }

    /** @return HasMany<Resident, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

    /** @return HasMany<Resident, $this> */
    public function head(): HasMany
    {
        return $this->hasMany(Resident::class)
            ->where('family_relation_code', 'kepala_keluarga');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
