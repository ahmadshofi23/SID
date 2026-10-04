<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Resident;
use App\Support\NameNormalizer;
use App\Support\Pii\Nik;
use App\Support\Pii\PiiCipher;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu-satunya pintu ke tabel `residents`.
 *
 * @extends GuardedRepository<Resident>
 */
final class ResidentRepository extends GuardedRepository
{
    protected function model(): string
    {
        return Resident::class;
    }

    /**
     * Penegakan pembatasan wilayah & keluarga (docs/04-arsitektur.md §4.1).
     *
     * @param  Builder<Resident>  $query
     * @return Builder<Resident>
     */
    protected function applyScope(Builder $query): Builder
    {
        // Anonim tidak boleh melihat satu baris pun. Statistik publik dilayani
        // tabel ringkasan `population_stats`, bukan tabel ini (ADR-007).
        if ($this->scope->userId === null) {
            return $query->whereRaw('1 = 0');
        }

        // Warga: hanya dirinya & anggota satu KK (RULE-11).
        if ($this->scope->isFamilyLimited()) {
            $familyId = $this->scope->familyId;

            if ($familyId === null) {
                // Akun warga belum tertaut ke KK -> tidak melihat apa pun.
                return $query->where('id', $this->scope->residentId ?? '-');
            }

            return $query->where(function (Builder $q) use ($familyId): void {
                $q->where('family_id', $familyId)
                    ->orWhere('id', $this->scope->residentId ?? '-');
            });
        }

        // Kepala Dusun / RT: hanya wilayah yang ditugaskan.
        if ($this->scope->isRegionLimited()) {
            $paths = $this->scope->regionPaths ?? [];

            if ($paths === []) {
                // Dibatasi wilayah tetapi belum ditugaskan -> fail closed.
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

    /**
     * Pencarian penduduk — FR-PDK-04.
     *
     * Urutan percobaan (konsekuensi tiadanya pg_trgm, KEP-002):
     *   1. NIK / nomor KK persis   -> lewat kolom hash, memakai indeks
     *   2. Nama berawalan          -> LIKE 'x%', memakai indeks
     *   3. Nama mengandung         -> LIKE '%x%', pemindaian (≈5.000 baris, < 10 ms)
     *
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Resident>
     */
    public function search(?string $term, array $filters = [], int $perPage = 50): CursorPaginator
    {
        $query = $this->scoped()->with(['region', 'family']);

        if ($term !== null && trim($term) !== '') {
            $term = trim($term);

            if (Nik::isWellFormed(Nik::normalize($term))) {
                $hash = app(PiiCipher::class)->hash(Nik::normalize($term));
                $query->where('nik_hash', $hash);
            } else {
                $norm = NameNormalizer::normalize($term);

                $query->where(function (Builder $q) use ($norm): void {
                    $q->where('full_name_norm', 'like', $norm.'%')
                        ->orWhere('full_name_norm', 'like', '%'.$norm.'%');
                });
            }
        }

        if (! empty($filters['region_id'])) {
            $query->where('region_id', $filters['region_id']);
        }

        if (! empty($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['religion_code'])) {
            $query->where('religion_code', $filters['religion_code']);
        }

        // Umur disaring lewat tanggal lahir agar indeks birth_date terpakai.
        if (isset($filters['age_min'])) {
            $query->where('birth_date', '<=', now()->subYears((int) $filters['age_min']));
        }

        if (isset($filters['age_max'])) {
            $query->where('birth_date', '>=', now()->subYears((int) $filters['age_max'] + 1));
        }

        return $query
            ->orderBy('full_name_norm')
            ->orderBy('id')
            ->cursorPaginate(min($perPage, 50));
    }

    /** Cari berdasarkan NIK persis. Mengembalikan null bila di luar lingkup. */
    public function findByNik(string $nik): ?Resident
    {
        $hash = app(PiiCipher::class)->hash(Nik::normalize($nik));

        return $this->scoped()->where('nik_hash', $hash)->first();
    }

    /** Jumlah penduduk aktif dalam lingkup pengguna. */
    public function countActive(): int
    {
        return $this->scoped()->active()->count();
    }
}
