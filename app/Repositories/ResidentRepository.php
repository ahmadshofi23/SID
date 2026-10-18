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
    /**
     * Beberapa penduduk sekaligus, berlingkup, beserta keluarga & wilayahnya.
     *
     * Dipakai layar yang menampilkan sedikit penduduk yang dipilih dari modul
     * lain (mis. antrean verifikasi akun warga). Mengambilnya satu per satu
     * berarti N kueri; memuat relasinya belakangan melanggar
     * Model::preventLazyLoading() yang menyala di pengembangan dan pengujian.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Resident>
     */
    public function findManyWithContext(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return $this->scoped()
            ->with(['family', 'region.parent.parent'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

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
    /**
     * Telusuri penduduk dalam lingkup pengguna untuk ekspor.
     *
     * Memakai `chunkById` agar berapa pun jumlah penduduknya, hanya 500 baris
     * yang berada di memori pada satu waktu.
     *
     * @param  callable(Resident): void  $callback
     */
    public function eachForExport(callable $callback): void
    {
        $this->scoped()
            ->with(['region.parent.parent', 'family'])
            ->orderBy('full_name_norm')
            ->chunkById(500, function ($residents) use ($callback): void {
                foreach ($residents as $resident) {
                    $callback($resident);
                }
            });
    }

    /* ====================== Pemeriksaan anomali (FR-PDK-13) ==================
     | Seluruh pemeriksaan di bawah MENGHORMATI lingkup akses: Kepala Dusun
     | hanya melihat anomali di dusunnya. Itu disengaja — laporan anomali
     | adalah alat kerja, bukan pintu belakang menuju data wilayah lain.
     */

    /**
     * NIK yang dipakai lebih dari satu penduduk aktif.
     *
     * Tidak mungkin terjadi lewat antarmuka (ditahan indeks unik), tetapi dapat
     * muncul dari perbaikan data manual lewat SQL atau migrasi dari sistem lama.
     *
     * @return list<array<string, mixed>>
     */
    public function duplicateNikGroups(int $limit = 50): array
    {
        $hashes = $this->scoped()
            ->toBase()
            ->select('nik_hash')
            ->groupBy('nik_hash')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->pluck('nik_hash');

        if ($hashes->isEmpty()) {
            return [];
        }

        return $this->scoped()
            ->whereIn('nik_hash', $hashes)
            ->orderBy('nik_hash')
            ->get()
            ->groupBy('nik_hash')
            ->map(fn ($group): array => [
                'nik' => $group->first()->nik_masked,
                'penduduk' => $group->map(fn (Resident $r): array => [
                    'id' => $r->id,
                    'nama' => $r->full_name,
                    'lahir' => $r->birth_date->format('d-m-Y'),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Nama yang sangat mirip dalam satu wilayah — calon data ganda (R-19).
     *
     * Kemiripan diukur dari nama ternormalisasi: tanpa gelar, tanda baca, dan
     * huruf besar/kecil. "Drs. Bambang Wijaya" dan "bambang wijaya" karena itu
     * terdeteksi sebagai sama.
     *
     * @return list<array<string, mixed>>
     */
    public function similarNames(int $limit = 50): array
    {
        $names = $this->scoped()
            ->toBase()
            ->select('full_name_norm', 'region_id')
            ->groupBy('full_name_norm', 'region_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->get();

        if ($names->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($names as $row) {
            $group = $this->scoped()
                ->with('region')
                ->where('full_name_norm', $row->full_name_norm)
                ->when(
                    $row->region_id === null,
                    fn ($q) => $q->whereNull('region_id'),
                    fn ($q) => $q->where('region_id', $row->region_id),
                )
                ->get();

            $result[] = [
                'nama' => $group->first()?->full_name,
                'wilayah' => $group->first()?->region?->name,
                'penduduk' => $group->map(fn (Resident $r): array => [
                    'id' => $r->id,
                    'nama' => $r->full_name,
                    'nik' => $r->nik_masked,
                    'lahir' => $r->birth_date->format('d-m-Y'),
                ])->values()->all(),
            ];
        }

        return $result;
    }

    /**
     * Kartu keluarga yang anggotanya ada tetapi tanpa kepala keluarga (RULE-02).
     *
     * @return list<array<string, mixed>>
     */
    public function familiesWithoutHead(int $limit = 50): array
    {
        $familyIds = $this->scoped()
            ->toBase()
            ->select('family_id')
            ->whereNotNull('family_id')
            ->groupBy('family_id')
            ->havingRaw("SUM(family_relation_code = 'kepala_keluarga') = 0")
            ->limit($limit)
            ->pluck('family_id');

        if ($familyIds->isEmpty()) {
            return [];
        }

        return $this->scoped()
            ->with('family')
            ->whereIn('family_id', $familyIds)
            ->get()
            ->groupBy('family_id')
            ->map(fn ($group): array => [
                'family_id' => $group->first()->family_id,
                'nomor_kk' => $group->first()->family?->kk_number_masked,
                'alamat' => $group->first()->family?->address,
                'jumlah_anggota' => $group->count(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function withoutRegion(int $limit = 50): array
    {
        return $this->scoped()
            ->whereNull('region_id')
            ->limit($limit)
            ->get()
            ->map(fn (Resident $r): array => [
                'id' => $r->id,
                'nama' => $r->full_name,
                'nik' => $r->nik_masked,
                'alamat' => $r->address,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function implausibleAges(int $maxAge = 110, int $limit = 50): array
    {
        return $this->scoped()
            ->active()
            ->whereDate('birth_date', '<', now()->subYears($maxAge))
            ->limit($limit)
            ->get()
            ->map(fn (Resident $r): array => [
                'id' => $r->id,
                'nama' => $r->full_name,
                'nik' => $r->nik_masked,
                'lahir' => $r->birth_date->format('d-m-Y'),
                'umur' => $r->age(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function withoutFamily(int $limit = 50): array
    {
        return $this->scoped()
            ->active()
            ->whereNull('family_id')
            ->limit($limit)
            ->get()
            ->map(fn (Resident $r): array => [
                'id' => $r->id,
                'nama' => $r->full_name,
                'nik' => $r->nik_masked,
                'umur' => $r->age(),
            ])
            ->values()
            ->all();
    }

    /**
     * Cari penduduk berdasarkan NIK, MENGABAIKAN lingkup akses.
     *
     * Khusus untuk impor massal. Alasannya sama dengan nikTakenByOther():
     * keunikan NIK berlaku se-desa. Bila impor memakai kueri ter-lingkup,
     * seorang Kepala Dusun yang mengimpor akan membuat duplikat untuk setiap
     * warga di luar dusunnya.
     *
     * Impor massal sendiri hanya dapat dijalankan pemegang izin
     * `penduduk.impor` — pada matriks RBAC, hanya Admin Desa.
     */
    public function findByNikIgnoringScope(string $nik): ?Resident
    {
        $hash = app(PiiCipher::class)->hash(Nik::normalize($nik));

        return Resident::query()->where('nik_hash', $hash)->first();
    }

    /**
     * Buat penduduk dari baris impor.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createFromImport(string $nik, array $attributes): Resident
    {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->fill($attributes);
        $resident->status = Resident::STATUS_ACTIVE;
        $resident->save();

        return $resident;
    }

    /**
     * Subkueri berisi ID penduduk yang BOLEH dilihat pengguna.
     *
     * Dipakai modul lain (mis. mutasi) yang menyimpan `resident_id` dan karena
     * itu harus mengikuti pembatasan wilayah/keluarga yang sama. Mengembalikan
     * subkueri, bukan daftar ID, agar tidak memuat ribuan ID ke memori.
     *
     * @return Builder<Resident>
     */
    public function visibleIdsQuery(): Builder
    {
        return $this->scoped()->select('id');
    }

    /**
     * Agregat demografi untuk tabel ringkasan statistik — FR-PDK-15, ADR-007.
     *
     * Sengaja MENGABAIKAN lingkup akses, dan itu aman: yang dikembalikan hanya
     * kombinasi dimensi beserta JUMLAHNYA — tidak ada satu pun baris penduduk,
     * nama, maupun NIK. Penyamaran sel kecil (ADR-008) dilakukan saat
     * penyajian, bukan di sini, agar laporan internal tetap akurat.
     *
     * Hanya penduduk AKTIF yang dihitung (RULE-03).
     *
     * @return list<array{
     *     region_id: string|null, gender: string, age_group: string,
     *     religion_code: string|null, education_code: string|null,
     *     occupation_code: string|null, marital_status_code: string|null,
     *     total: int
     * }>
     */
    public function demographicAggregates(): array
    {
        $rows = Resident::query()
            ->toBase()
            ->selectRaw(<<<'SQL'
                region_id,
                gender,
                CASE
                    WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 5  THEN '0-4'
                    WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 15 THEN '5-14'
                    WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 25 THEN '15-24'
                    WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 45 THEN '25-44'
                    WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 65 THEN '45-64'
                    ELSE '65+'
                END AS age_group,
                religion_code, education_code, occupation_code, marital_status_code,
                COUNT(*) AS total
            SQL)
            ->whereNull('deleted_at')
            ->where('status', Resident::STATUS_ACTIVE)
            ->groupBy(
                'region_id', 'gender', 'age_group',
                'religion_code', 'education_code', 'occupation_code', 'marital_status_code',
            )
            ->get();

        return $rows->map(fn (object $row): array => [
            'region_id' => $row->region_id === null ? null : (string) $row->region_id,
            'gender' => (string) $row->gender,
            'age_group' => (string) $row->age_group,
            'religion_code' => $row->religion_code === null ? null : (string) $row->religion_code,
            'education_code' => $row->education_code === null ? null : (string) $row->education_code,
            'occupation_code' => $row->occupation_code === null ? null : (string) $row->occupation_code,
            'marital_status_code' => $row->marital_status_code === null
                ? null : (string) $row->marital_status_code,
            'total' => (int) $row->total,
        ])->values()->all();
    }

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
