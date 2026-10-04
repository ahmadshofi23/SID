<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Family;
use App\Support\Pii\Nik;
use App\Support\Pii\PiiCipher;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu-satunya pintu ke tabel `families`.
 *
 * @extends GuardedRepository<Family>
 */
final class FamilyRepository extends GuardedRepository
{
    protected function model(): string
    {
        return Family::class;
    }

    /**
     * @param  Builder<Family>  $query
     * @return Builder<Family>
     */
    protected function applyScope(Builder $query): Builder
    {
        if ($this->scope->userId === null) {
            return $query->whereRaw('1 = 0');
        }

        // Warga hanya melihat KK-nya sendiri (RULE-11).
        if ($this->scope->isFamilyLimited()) {
            return $query->where('id', $this->scope->familyId ?? '-');
        }

        if ($this->scope->isRegionLimited()) {
            $paths = $this->scope->regionPaths ?? [];

            if ($paths === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('region', function (Builder $q) use ($paths): void {
                $q->where(function (Builder $inner) use ($paths): void {
                    foreach ($paths as $path) {
                        $inner->orWhere('path', $path)
                            ->orWhere('path', 'like', $path.'/%');
                    }
                });
            });
        }

        return $query;
    }

    public function findByKkNumber(string $kkNumber): ?Family
    {
        $hash = app(PiiCipher::class)->hash(Nik::normalize($kkNumber));

        return $this->scoped()->where('kk_number_hash', $hash)->first();
    }

    public function countAll(): int
    {
        return $this->scoped()->count();
    }

    /**
     * Daftar kartu keluarga dengan pencarian & filter.
     *
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Family>
     */
    public function search(?string $term, array $filters = [], int $perPage = 50): CursorPaginator
    {
        $query = $this->scoped()->with(['region.parent.parent'])->withCount('members');

        if ($term !== null && trim($term) !== '') {
            $term = trim($term);

            if (Nik::isWellFormed(Nik::normalize($term))) {
                $query->where('kk_number_hash', app(PiiCipher::class)->hash(Nik::normalize($term)));
            } else {
                $query->where('address', 'like', '%'.$term.'%');
            }
        }

        if (! empty($filters['region_id'])) {
            $query->where('region_id', $filters['region_id']);
        }

        if (! empty($filters['welfare_status_code'])) {
            $query->where('welfare_status_code', $filters['welfare_status_code']);
        }

        return $query->orderBy('address')->orderBy('id')->cursorPaginate(min($perPage, 50));
    }

    /**
     * Apakah nomor KK sudah dipakai keluarga lain (lihat catatan keunikan
     * se-desa pada ResidentRepository::nikTakenByOther).
     */
    public function kkNumberTakenByOther(string $kkNumber, ?string $exceptId = null): bool
    {
        $hash = app(PiiCipher::class)->hash(Nik::normalize($kkNumber));

        return Family::query()
            ->where('kk_number_hash', $hash)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }
}
