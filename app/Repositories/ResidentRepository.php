<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Region;
use App\Models\Resident;
use App\Support\NameNormalizer;
use App\Support\Pii\Nik;
use App\Support\Pii\PiiCipher;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
        $query = $this->scoped()->with(['region.parent.parent', 'family']);

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

    /** Jumlah seluruh penduduk (termasuk meninggal/pindah) dalam lingkup. */
    public function countAll(): int
    {
        return $this->scoped()->count();
    }

    /**
     * Ringkasan status penduduk dalam lingkup pengguna.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->scoped()
            ->selectRaw('status, COUNT(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status')
            ->all();

        return $counts;
    }

    /**
     * Anggota satu keluarga, dalam lingkup pengguna.
     *
     * @return Collection<int, Resident>
     */
    public function membersOfFamily(string $familyId): Collection
    {
        return $this->scoped()
            ->where('family_id', $familyId)
            ->orderByRaw("FIELD(family_relation_code, 'kepala_keluarga') DESC")
            ->orderBy('birth_date')
            ->get();
    }

    /**
     * Pencarian ringkas untuk autocomplete (FR-PDK-04).
     *
     * Hanya mengembalikan field yang diperlukan agar petugas yakin memilih
     * orang yang benar: nama, umur, wilayah, dan NIK TER-MASKING. NIK penuh
     * tidak pernah ikut di sini (RULE-07).
     *
     * @return list<array{id: string, nama: string, umur: int, nik: string, wilayah: string}>
     */
    public function suggest(string $term, int $limit = 10): array
    {
        // Di bawah 3 karakter, hasilnya terlalu banyak untuk berguna — dan
        // membuka peluang mengambil daftar penduduk sedikit demi sedikit.
        if (mb_strlen(trim($term)) < 3) {
            return [];
        }

        $results = [];

        foreach ($this->search($term, [], $limit)->items() as $resident) {
            $region = $resident->region;

            $results[] = [
                'id' => $resident->id,
                'nama' => $resident->full_name,
                'umur' => $resident->age(),
                'nik' => $resident->nik_masked,
                'wilayah' => $region instanceof Region ? $region->name : '-',
            ];
        }

        return $results;
    }

    /**
     * Apakah NIK sudah dipakai penduduk lain yang masih aktif (RULE-01)?
     *
     * Sengaja MENGABAIKAN lingkup akses: keunikan NIK berlaku se-desa, bukan
     * se-dusun. Bila memakai query ter-lingkup, Kepala Dusun bisa membuat NIK
     * ganda karena tidak melihat penduduk dusun lain. Itu sebabnya metode ini
     * hanya mengembalikan boolean — tidak pernah membocorkan data penduduknya.
     */
    public function countInRegionIgnoringScope(string $regionId): int
    {
        /*
         * Sengaja MENGABAIKAN lingkup akses, sama seperti nikTakenByOther().
         *
         * Dipakai sebagai pengaman sebelum menghapus wilayah: bila memakai
         * query ter-lingkup, seorang Kepala Dusun bisa menghapus wilayah yang
         * tampak kosong baginya padahal masih dihuni penduduk yang tidak ia
         * lihat. Metode ini hanya mengembalikan ANGKA — tidak pernah
         * membocorkan satu baris data penduduk pun.
         */
        return Resident::query()->where('region_id', $regionId)->count();
    }

    public function nikTakenByOther(string $nik, ?string $exceptResidentId = null): bool
    {
        $hash = app(PiiCipher::class)->hash(Nik::normalize($nik));

        return Resident::query()
            ->where('nik_hash', $hash)
            ->when($exceptResidentId !== null, fn ($q) => $q->whereKeyNot($exceptResidentId))
            ->exists();
    }
}
