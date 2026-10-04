<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedPii;
use App\Support\NameNormalizer;
use App\Support\Pii\Nik;
use App\Support\Pii\PiiCipher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Penduduk — entitas paling sensitif dalam sistem.
 *
 * TABEL DIJAGA: akses hanya melalui App\Repositories\ResidentRepository
 * (config/sid.php guarded_tables). Mengakses model ini langsung dari
 * Controller/Command/Job akan menggagalkan tests/Architecture.
 */
/**
 * @property string $id
 * @property string|null $family_id
 * @property string|null $region_id
 * @property string|null $nik_enc
 * @property string $nik_hash
 * @property string $nik_masked
 * @property string $full_name
 * @property string $full_name_norm
 * @property Carbon $birth_date
 * @property string $gender
 * @property string $family_relation_code
 * @property string $status
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $deleted_at
 * @property-read Family|null $family
 * @property-read Region|null $region
 */
final class Resident extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Kolom generated & turunan PII tidak boleh diisi dari masukan pengguna. */
    protected $guarded = [
        'id', 'nik_enc', 'nik_hash', 'nik_masked',
        'nik_hash_active', 'family_head_key', 'full_name_norm',
    ];

    /** Status yang dihitung sebagai penduduk aktif (RULE-03). */
    public const STATUS_ACTIVE = 'aktif';

    protected $hidden = ['nik_enc', 'nik_hash', 'father_nik_hash', 'mother_nik_hash'];

    protected function casts(): array
    {
        return [
            'nik_enc' => EncryptedPii::class,
            'birth_date' => 'date',
            'is_poor' => 'boolean',
            'status_changed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** Set NIK: enkripsi + hash + masking sekaligus (ADR-004). */
    public function setNik(string $nik): void
    {
        $normalized = Nik::normalize($nik);

        $this->nik_enc = $normalized;
        $this->nik_hash = app(PiiCipher::class)->hash($normalized);
        $this->nik_masked = Nik::mask($normalized);
    }

    /** NIK apa adanya — PEMAKAIAN WAJIB dicatat di audit log (RULE-07). */
    public function revealNik(): ?string
    {
        return $this->nik_enc;
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function age(): int
    {
        return (int) $this->birth_date->diffInYears(now());
    }

    public function isHeadOfFamily(): bool
    {
        return $this->family_relation_code === 'kepala_keluarga';
    }

    /** Penduduk aktif saja — dipakai statistik & kelayakan pemohon surat. */
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    protected static function booted(): void
    {
        // full_name_norm selalu sinkron dengan full_name, tanpa bergantung
        // pada pemanggil mengingatnya.
        self::saving(function (self $resident): void {
            if ($resident->isDirty('full_name')) {
                $resident->full_name_norm = NameNormalizer::normalize(
                    (string) $resident->full_name
                );
            }

            if ($resident->isDirty('status')) {
                $resident->status_changed_at = now();
            }
        });
    }
}
