<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\LetterType;
use App\Models\Official;
use App\Models\Region;
use App\Repositories\FamilyRepository;
use App\Services\Population\StatisticsService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Model baca untuk portal publik — FR-PRT-01, FR-PRT-04, FR-PRT-05.
 *
 * MENGAPA KELAS INI TIDAK PUNYA KONSTRUKTOR
 * -----------------------------------------
 * Seluruh dependensinya diserahkan per METODE, bukan disimpan di properti.
 * Alasannya sama dengan KEP-009 untuk controller: StatisticsService dan
 * FamilyRepository membawa AccessScope, dan objek yang menyimpannya berisiko
 * membawa lingkup pengguna pertama ke pengguna berikutnya begitu ia ikut
 * bertahan lebih lama dari satu permintaan. Portal publik justru halaman yang
 * paling sering dibuka orang berbeda-beda secara berurutan, jadi di sinilah
 * kebocoran seperti itu paling mudah terjadi dan paling sulit disadari.
 *
 * YANG TIDAK BOLEH MASUK KE SINI: apa pun yang mengembalikan BARIS dari tabel
 * dijaga. Portal publik hanya berhak atas angka agregat (BR-21) dan atas data
 * yang memang dipublikasikan desa — profil, perangkat, wilayah, jenis layanan.
 */
final class PortalService
{
    /**
     * "Desa dalam angka" untuk beranda & halaman profil (docs/07 §5.5).
     *
     * @return array{penduduk: int, keluarga: int, dusun: int, rt: int, computed_at: string|null}
     */
    public function villageInNumbers(
        StatisticsService $statistics,
        FamilyRepository $families,
    ): array {
        return [
            // Dari tabel ringkasan `population_stats`, bukan dari `residents`.
            'penduduk' => (int) $statistics->summary()['total'],
            'keluarga' => $families->countAllIgnoringScope(),
            'dusun' => Region::query()->level('dusun')->count(),
            'rt' => Region::query()->level('rt')->count(),
            'computed_at' => $statistics->computedAt()?->toIso8601String(),
        ];
    }

    /**
     * Standar pelayanan yang dibuka ke publik — FR-PRT-04, BR-23.
     *
     * `is_public` berarti "dapat diajukan warga daring"; jenis surat yang
     * hanya dilayani di loket TIDAK ditampilkan di sini, karena halaman ini
     * adalah janji layanan desa kepada warga, bukan daftar isi basis data.
     *
     * @return Collection<int, LetterType>
     */
    public function publicServices(): Collection
    {
        return LetterType::query()
            ->active()
            ->where('is_public', true)
            ->orderBy('name')
            ->get();
    }

    /** Satu layanan berdasarkan kodenya; null bila tidak dibuka ke publik. */
    public function findPublicService(string $code): ?LetterType
    {
        return LetterType::query()
            ->active()
            ->where('is_public', true)
            ->where('code', $code)
            ->first();
    }

    /**
     * Struktur organisasi untuk halaman pemerintahan — FR-PRT-01.
     *
     * Dibaca dari `officials`, bukan `users`: tidak semua perangkat punya akun,
     * dan tabel akun memuat surel, nomor ponsel, serta keadaan 2FA yang tidak
     * ada urusannya dengan halaman publik.
     *
     * @return Collection<int, Official>
     */
    public function organizationChart(): Collection
    {
        return Official::query()
            ->active()
            // Wilayah di-eager-load: kepala dusun menampilkan dusunnya, dan
            // memuatnya per baris akan melanggar Model::preventLazyLoading()
            // yang menyala di pengembangan, pengujian, dan staging.
            ->with('region')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Dusun → RW → RT untuk halaman wilayah.
     *
     * @return Collection<int, Region>
     */
    public function regionTree(): Collection
    {
        return Region::query()
            ->level('dusun')
            ->with(['children' => fn ($q) => $q->orderBy('code'), 'children.children' => fn ($q) => $q->orderBy('code')])
            ->orderBy('code')
            ->get();
    }
}
