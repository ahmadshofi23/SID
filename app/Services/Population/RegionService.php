<?php

declare(strict_types=1);

namespace App\Services\Population;

use App\Models\Region;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan wilayah — FR-PDK-14.
 *
 * PENTING: `regions.path` adalah dasar pembatasan akses Kepala Dusun
 * (docs/04 §4.1). Setiap perubahan struktur wilayah WAJIB menyinkronkan path
 * seluruh turunannya — bila tidak, seorang Kepala Dusun bisa kehilangan akses
 * ke wilayahnya sendiri, atau justru memperoleh akses ke wilayah lain.
 */
final class RegionService
{
    private const LEVEL_PARENT = [
        'dusun' => null,
        'rw' => 'dusun',
        'rt' => 'rw',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ResidentRepository $residents,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Region
    {
        $this->guardHierarchy((string) $data['level'], $data['parent_id'] ?? null);
        $this->guardCodeUnique(
            (string) $data['code'], $data['parent_id'] ?? null, null
        );

        return DB::transaction(function () use ($data): Region {
            $region = new Region;
            $region->fill($this->fillable($data));
            $region->save();   // path dihitung otomatis di model (booted)

            $this->audit->log('region.created', $region, context: [
                'nama' => $region->name,
                'path' => $region->path,
            ]);

            return $region;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Region $region, array $data): Region
    {
        if (isset($data['code'])) {
            $this->guardCodeUnique(
                (string) $data['code'],
                $data['parent_id'] ?? $region->parent_id,
                $region->id
            );
        }

        return DB::transaction(function () use ($region, $data): Region {
            $before = $region->getOriginal();

            $region->fill($this->fillable($data));
            $region->save();

            $this->audit->logModelChange('region.updated', $region, $before);

            return $region;
        });
    }

    public function delete(Region $region): void
    {
        $childCount = Region::query()->where('parent_id', $region->id)->count();

        if ($childCount > 0) {
            throw ValidationException::withMessages([
                'region' => sprintf(
                    'Wilayah ini masih memiliki %d wilayah di bawahnya. '
                    .'Hapus atau pindahkan wilayah tersebut terlebih dahulu.',
                    $childCount
                ),
            ]);
        }

        /*
         * Wilayah yang masih dihuni tidak boleh dihapus: penduduknya akan
         * kehilangan region_id dan otomatis keluar dari pembatasan akses
         * Kepala Dusun — mereka menjadi "tak terlihat" oleh siapa pun yang
         * dibatasi wilayah.
         */
        $residentCount = $this->residents->countInRegionIgnoringScope($region->id);

        if ($residentCount > 0) {
            throw ValidationException::withMessages([
                'region' => sprintf(
                    'Wilayah ini masih dihuni %d penduduk. Pindahkan penduduknya '
                    .'terlebih dahulu sebelum menghapus wilayah.',
                    $residentCount
                ),
            ]);
        }

        DB::transaction(function () use ($region): void {
            $this->audit->log('region.deleted', $region, context: [
                'nama' => $region->name, 'path' => $region->path,
            ]);
            $region->delete();
        });
    }

    /**
     * Pohon wilayah lengkap untuk ditampilkan & dipilih di formulir.
     *
     * @return Collection<int, Region>
     */
    public function tree(): Collection
    {
        /*
         * Seluruh wilayah diambil dalam SATU kueri, lalu pohonnya dirakit di
         * memori dan relasi `children` dipasang eksplisit.
         *
         * Pendekatan sebelumnya — with(['children.children']) — mengandung
         * asumsi kedalaman: ia memuat dua tingkat sementara penyaji menelusuri
         * tiga, sehingga halaman Wilayah gagal dengan HTTP 500 di tingkat RT.
         * Menambah satu `.children` lagi hanya memindahkan masalahnya satu
         * tingkat ke bawah; merakit di memori menghapus asumsinya sama sekali.
         *
         * Jumlah wilayah satu desa berkisar puluhan, jadi memuat semuanya jauh
         * lebih murah daripada kueri bertingkat.
         */
        $all = Region::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $byParent = $all->groupBy('parent_id');

        foreach ($all as $region) {
            $region->setRelation(
                'children',
                new EloquentCollection($byParent->get($region->id, collect())->all()),
            );
        }

        return $all->filter(fn (Region $region): bool => $region->parent_id === null)
            ->values();
    }

    /**
     * Daftar datar RT untuk dropdown, lengkap dengan jalur yang terbaca.
     *
     * @return array<string, string> id => "Dusun Krajan / RW 01 / RT 003"
     */
    public function rtOptions(): array
    {
        $options = [];

        foreach (Region::query()->with('parent.parent')->where('level', 'rt')
            ->orderBy('path')->get() as $rt) {
            $options[$rt->id] = $rt->displayPath();
        }

        return $options;
    }

    private function guardHierarchy(string $level, ?string $parentId): void
    {
        $expectedParentLevel = self::LEVEL_PARENT[$level] ?? null;

        if ($expectedParentLevel === null) {
            if ($parentId !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Dusun berada di tingkat teratas dan tidak memiliki induk.',
                ]);
            }

            return;
        }

        if ($parentId === null) {
            throw ValidationException::withMessages([
                'parent_id' => sprintf(
                    'Pilih %s induk terlebih dahulu.', strtoupper($expectedParentLevel)
                ),
            ]);
        }

        $parent = Region::query()->find($parentId);

        if ($parent === null || $parent->level !== $expectedParentLevel) {
            throw ValidationException::withMessages([
                'parent_id' => sprintf(
                    'Induk yang dipilih harus bertingkat %s.', strtoupper($expectedParentLevel)
                ),
            ]);
        }
    }

    private function guardCodeUnique(string $code, ?string $parentId, ?string $exceptId): void
    {
        $exists = Region::query()
            ->where('code', $code)
            ->where(fn ($q) => $parentId === null
                ? $q->whereNull('parent_id')
                : $q->where('parent_id', $parentId))
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => "Kode \"{$code}\" sudah dipakai pada wilayah induk yang sama.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillable(array $data): array
    {
        $allowed = [
            'parent_id', 'level', 'code', 'name', 'head_name', 'head_phone', 'sort_order',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
