<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\LetterType;
use App\Models\Official;
use App\Models\Post;
use App\Models\Region;
use App\Repositories\FamilyRepository;
use App\Services\Population\StatisticsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * Konten portal yang BOLEH dilihat publik, satu jenis.
     *
     * Saringannya satu-satunya: Post::scopePublished(). Tidak ada varian lain
     * di mana pun — pengumuman yang dijadwalkan untuk besok tidak boleh bocor
     * hari ini hanya karena satu halaman menyusun kuerinya sendiri.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function publishedOfType(string $type, int $perPage = 12): LengthAwarePaginator
    {
        return Post::query()
            ->published()
            ->ofType($type)
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    /**
     * Agenda kegiatan, dipisah menjadi mendatang dan sudah lewat.
     *
     * Diurutkan MENAIK untuk yang mendatang: yang paling dekat adalah yang
     * paling berguna. Mengurutkannya menurun seperti berita akan menaruh
     * kegiatan tahun depan di atas rapat besok.
     *
     * @return array{mendatang: Collection<int, Post>, lewat: Collection<int, Post>}
     */
    public function agenda(int $limitLewat = 10): array
    {
        $batas = now();

        return [
            'mendatang' => Post::query()
                ->published()
                ->ofType(Post::TYPE_AGENDA)
                ->where(fn ($q) => $q
                    ->where('event_end_at', '>=', $batas)
                    ->orWhere(fn ($i) => $i->whereNull('event_end_at')->where('event_start_at', '>=', $batas)))
                ->orderBy('event_start_at')
                ->get(),
            'lewat' => Post::query()
                ->published()
                ->ofType(Post::TYPE_AGENDA)
                ->where(fn ($q) => $q
                    ->where('event_end_at', '<', $batas)
                    ->orWhere(fn ($i) => $i->whereNull('event_end_at')->where('event_start_at', '<', $batas)))
                ->orderByDesc('event_start_at')
                ->limit($limitLewat)
                ->get(),
        ];
    }

    /** Satu tulisan dari slug-nya; null bila belum/tidak terbit. */
    public function findPublished(string $slug): ?Post
    {
        return Post::query()->published()->where('slug', $slug)->first();
    }

    /**
     * Tulisan terbaru untuk beranda — berita dan pengumuman bercampur.
     *
     * Dicampur dengan sengaja: warga yang membuka halaman depan ingin tahu
     * "apa yang baru di desa", dan pembedaan berita/pengumuman adalah
     * kategori administrasi, bukan cara mereka membacanya.
     *
     * @return Collection<int, Post>
     */
    public function latestForHome(int $limit = 3): Collection
    {
        return Post::query()
            ->published()
            ->whereIn('type', [Post::TYPE_BERITA, Post::TYPE_PENGUMUMAN])
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /** Seluruh tulisan terbit, untuk peta situs. */
    /** @return Collection<int, Post> */
    public function publishedForSitemap(): Collection
    {
        return Post::query()
            ->published()
            ->orderByDesc('published_at')
            ->get(['id', 'type', 'slug', 'published_at', 'updated_at']);
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
