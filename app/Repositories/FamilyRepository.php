<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Family;
use App\Support\Pii\Nik;
use App\Support\Pii\PiiCipher;
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
}
