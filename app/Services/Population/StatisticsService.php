<?php

declare(strict_types=1);

namespace App\Services\Population;

use App\Models\PopulationStat;
use App\Repositories\ResidentRepository;
use App\Support\ReferenceData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statistik agregat — FR-PDK-15, ADR-007, ADR-008.
 *
 * Dua prinsip yang menentukan seluruh isi kelas ini:
 *
 * 1. Statistik dibaca dari tabel ringkasan, BUKAN dari tabel `residents`.
 *    Selain lebih cepat, halaman publik jadi tidak punya jalan menuju data
 *    pribadi sama sekali.
 *
 * 2. Sel bernilai 1–4 orang DISAMARKAN saat disajikan (ADR-008). Tanpa itu,
 *    kombinasi saringan yang sempit — satu RT, satu pekerjaan, satu kelompok
 *    umur — dapat menunjuk ke satu orang tertentu. Penyamaran dilakukan di
 *    lapis penyajian, bukan di tabel, agar laporan internal tetap akurat.
 */
final class StatisticsService
{
    private const AGE_GROUPS = ['0-4', '5-14', '15-24', '25-44', '45-64', '65+'];

    public function __construct(private readonly ResidentRepository $residents) {}

    /**
     * Hitung ulang seluruh tabel ringkasan.
     *
     * Dijalankan terjadwal tiap jam, dan LANGSUNG setelah impor massal atau
     * mutasi — agar angka tidak membingungkan petugas yang baru saja
     * memasukkan data (docs/05 §4).
     */
    public function refresh(): int
    {
        $computedAt = now();

        $rows = $this->residents->demographicAggregates();

        $payload = [];

        foreach ($rows as $row) {
            $total = $row['total'];
            unset($row['total']);

            $payload[] = [
                ...$row,
                'total' => $total,
                'computed_at' => $computedAt,
                'dimension_hash' => hash('sha256', json_encode($row) ?: ''),
            ];
        }

        DB::transaction(function () use ($payload, $computedAt): void {
            /*
             * Baris lama DIHAPUS, bukan ditimpa.
             *
             * Bila suatu kombinasi tidak lagi punya penduduk (mis. satu-satunya
             * warga di RT itu pindah), upsert akan meninggalkan angka lama yang
             * sudah tidak benar. Menghapus lebih dulu menjamin tabel ringkasan
             * selalu mencerminkan keadaan nyata.
             */
            PopulationStat::query()->delete();

            foreach (array_chunk($payload, 500) as $chunk) {
                PopulationStat::query()->insert($chunk);
            }

            if ($payload === []) {
                // Tetap catat waktu hitung agar portal dapat menampilkan
                // "Data per: ..." walau desanya belum punya penduduk.
                PopulationStat::query()->insert([[
                    'region_id' => null, 'gender' => null, 'age_group' => null,
                    'religion_code' => null, 'education_code' => null,
                    'occupation_code' => null, 'marital_status_code' => null,
                    'total' => 0, 'computed_at' => $computedAt,
                    'dimension_hash' => hash('sha256', 'kosong'),
                ]]);
            }
        });

        return count($payload);
    }

    public function computedAt(): ?Carbon
    {
        return PopulationStat::query()->max('computed_at') === null
            ? null
            : Carbon::parse((string) PopulationStat::query()->max('computed_at'));
    }

    /**
     * Ringkasan untuk ditampilkan.
     *
     * @param  string|null  $regionId  batasi pada satu wilayah (dan turunannya)
     * @return array<string, mixed>
     */
    public function summary(?string $regionId = null, bool $suppressSmallCells = true): array
    {
        $base = PopulationStat::query()
            ->when($regionId !== null, fn ($q) => $q->where('region_id', $regionId));

        $total = (int) (clone $base)->sum('total');

        return [
            'total' => $total,
            'computed_at' => $this->computedAt()?->toIso8601String(),
            'by_gender' => $this->aggregate($base, 'gender', [
                'L' => 'Laki-laki', 'P' => 'Perempuan',
            ], $suppressSmallCells),
            'pyramid' => $this->pyramid($base, $suppressSmallCells),
            'by_religion' => $this->aggregate(
                $base, 'religion_code', ReferenceData::get('religions'), $suppressSmallCells
            ),
            'by_education' => $this->aggregate(
                $base, 'education_code', ReferenceData::get('educations'), $suppressSmallCells
            ),
            'by_occupation' => $this->aggregate(
                $base, 'occupation_code', ReferenceData::get('occupations'), $suppressSmallCells, 10
            ),
            'by_marital_status' => $this->aggregate(
                $base, 'marital_status_code', ReferenceData::get('marital_statuses'), $suppressSmallCells
            ),
        ];
    }

    /**
     * Piramida penduduk: kelompok umur × jenis kelamin.
     *
     * @param  Builder<PopulationStat>  $base
     * @return list<array{group: string, L: int|string, P: int|string}>
     */
    private function pyramid(Builder $base, bool $suppress): array
    {
        $rows = (clone $base)
            ->toBase()
            ->selectRaw('age_group, gender, SUM(total) AS jumlah')
            ->groupBy('age_group', 'gender')
            ->get()
            ->groupBy('age_group');

        $result = [];

        foreach (self::AGE_GROUPS as $group) {
            $byGender = $rows->get($group, collect())->keyBy('gender');

            $result[] = [
                'group' => $group,
                'L' => $this->mask((int) ($byGender->get('L')->jumlah ?? 0), $suppress),
                'P' => $this->mask((int) ($byGender->get('P')->jumlah ?? 0), $suppress),
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<PopulationStat>  $base
     * @param  array<string, string>  $labels
     * @return list<array{code: string, label: string, total: int|string}>
     */
    private function aggregate(
        Builder $base,
        string $column,
        array $labels,
        bool $suppress,
        ?int $limit = null,
    ): array {
        $rows = (clone $base)
            ->toBase()
            ->selectRaw("{$column} AS kode, SUM(total) AS jumlah")
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('jumlah')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();

        /** @var list<array{code: string, label: string, total: int|string}> $result */
        $result = $rows->map(fn (object $row): array => [
            'code' => (string) $row->kode,
            'label' => $labels[(string) $row->kode] ?? (string) $row->kode,
            'total' => $this->mask((int) $row->jumlah, $suppress),
        ])->values()->all();

        return $result;
    }

    /**
     * ADR-008 — penyamaran sel kecil.
     *
     * Mengembalikan "< 5" sebagai TEKS, bukan angka 0. Mengganti dengan nol
     * akan membuat jumlah tidak konsisten dan menyesatkan pembaca; menandainya
     * sebagai "< 5" jujur menyatakan bahwa angkanya ada tetapi sengaja
     * tidak dirinci.
     */
    private function mask(int $value, bool $suppress): int|string
    {
        $threshold = (int) config('sid.stats_small_cell_threshold', 5);

        return $suppress && $value > 0 && $value < $threshold ? "< {$threshold}" : $value;
    }
}
