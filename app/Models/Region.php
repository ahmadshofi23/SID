<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Wilayah: Dusun -> RW -> RT.
 *
 * `path` (materialized path) adalah dasar pembatasan akses Kepala Dusun —
 * lihat App\Repositories\ResidentRepository.
 */
/**
 * @property string $id
 * @property string|null $parent_id
 * @property string $level
 * @property string $code
 * @property string $name
 * @property string $path
 * @property-read Region|null $parent
 * @property-read Collection<int, Region> $children
 */
final class Region extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    public const LEVELS = ['dusun', 'rw', 'rt'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return HasMany<Resident, $this> */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }

    /**
     * Hitung ulang `path` dari rantai induk.
     * Dipanggil saat wilayah dibuat atau dipindahkan.
     */
    public function buildPath(): string
    {
        $segment = Str::slug($this->level.'-'.$this->code);

        // Memeriksa parent_id lebih dulu, bukan langsung mengakses ->parent:
        // pada wilayah teratas (dusun) relasi itu tidak pernah dimuat, dan
        // mengaksesnya memicu pelanggaran larangan lazy loading.
        if ($this->parent_id === null) {
            return $segment;
        }

        return $this->parent->path.'/'.$segment;
    }

    /**
     * Bentuk ringkas untuk kolom tabel: "Krajan / RT 003".
     *
     * Nama RT saja TIDAK CUKUP: setiap dusun punya RT 001, sehingga kolom
     * wilayah menjadi ambigu dan petugas tidak dapat membedakan dua warga
     * dari dusun berbeda.
     */
    public function shortPath(): string
    {
        if ($this->level !== 'rt' || $this->parent_id === null) {
            return $this->name;
        }

        $rw = $this->parent;
        $dusun = $rw->parent_id === null ? null : $rw->parent;

        if ($dusun === null) {
            return $this->name;
        }

        return str_replace('Dusun ', '', $dusun->name).' / '.$this->name;
    }

    /** "Dusun Krajan / RW 02 / RT 003" */
    public function displayPath(): string
    {
        $names = [];
        $node = $this;

        while ($node !== null) {
            $names[] = $node->name;

            // Berhenti berdasarkan parent_id, BUKAN dengan mengakses ->parent
            // lalu memeriksa null — pada wilayah teratas relasi itu tidak
            // dimuat, dan mengaksesnya memicu pelanggaran lazy loading.
            $node = $node->parent_id === null ? null : $node->parent;
        }

        return implode(' / ', array_reverse($names));
    }

    protected static function booted(): void
    {
        self::saving(function (self $region): void {
            $region->path = $region->buildPath();
        });

        // Memindahkan wilayah mengubah path seluruh turunannya. Bila tidak
        // disinkronkan, pembatasan akses Kepala Dusun akan salah.
        self::saved(function (self $region): void {
            if ($region->wasChanged('path')) {
                $region->children->each->save();
            }
        });
    }
}
