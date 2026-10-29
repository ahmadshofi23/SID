<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Letak komponen halaman Inertia — huruf besar-kecilnya ikut diperiksa.
 *
 * MENGAPA UJI INI ADA
 * -------------------
 * Bawaan inertiajs/inertia-laravel adalah `resource_path('js/pages')` dengan
 * huruf kecil, sementara halaman proyek ini ada di `js/Pages`. Di macOS
 * sistem berkasnya tidak membedakan huruf besar-kecil, sehingga ketidakcocokan
 * itu TIDAK TERLIHAT SAMA SEKALI: `->component(...)` di dalam uji lulus, dan
 * halaman terbuka normal di peramban. Di Linux — CI dan server desa — jalur
 * itu tidak ada, dan setiap `->component(...)` gagal dengan "Inertia page
 * component file [...] does not exist".
 *
 * Itu ditemukan hanya setelah CI akhirnya berhasil memasang dependensinya,
 * yang selama ini gagal lebih dulu karena batas PHP.
 *
 * MENGAPA PEMERIKSAANNYA TIDAK MEMAKAI is_dir()
 * ---------------------------------------------
 * `is_dir('resources/js/pages')` mengembalikan TRUE di macOS meski
 * direktorinya bernama `Pages`. Memakainya berarti uji ini ikut buta terhadap
 * cacat yang justru hendak dijaganya, dan ia akan hijau di tempat ia ditulis
 * lalu merah hanya di tempat ia dipercaya.
 *
 * Yang dipakai di sini adalah `scandir()` atas direktori induknya, lalu
 * perbandingan nama secara PERSIS — cara yang sama dengan yang dilakukan
 * sistem berkas Linux ketika membuka jalur itu.
 */
final class InertiaPagePathTest extends TestCase
{
    #[Test]
    #[TestDox('Setiap jalur halaman Inertia benar-benar ada, huruf besar-kecilnya persis')]
    public function jalur_halaman_ada_dengan_huruf_persis(): void
    {
        /** @var list<string> $paths */
        $paths = config('inertia.pages.paths', []);

        self::assertNotEmpty($paths, 'Tidak ada jalur halaman Inertia yang dikonfigurasi.');

        foreach ($paths as $path) {
            self::assertTrue(
                $this->existsCaseSensitively($path),
                sprintf(
                    'Jalur halaman Inertia [%s] tidak ada di sistem berkas yang '
                    .'membedakan huruf besar-kecil. Di macOS ini lolos; di Linux '
                    .'setiap ->component(...) akan gagal.',
                    $path,
                ),
            );
        }
    }

    #[Test]
    #[TestDox('Pencari berkas Inertia menemukan komponen yang memang ada')]
    public function pencari_berkas_menemukan_komponen(): void
    {
        /*
         * Satu komponen nyata sebagai batu uji. Bukan sekadar memeriksa
         * konfigurasinya benar, melainkan bahwa rantai lengkapnya — konfigurasi,
         * pencari berkas, ekstensi — benar-benar sampai ke sebuah berkas.
         */
        $resolved = app('inertia.view-finder')->find('Kependudukan/Penduduk/Show');

        self::assertTrue(
            $this->existsCaseSensitively($resolved),
            sprintf('Komponen ditemukan di [%s], tetapi jalurnya tidak sahih di Linux.', $resolved),
        );
    }

    /**
     * Seperti `file_exists()`, tetapi membedakan huruf besar-kecil pada
     * SETIAP ruas jalur — termasuk di macOS, yang sistem berkasnya tidak.
     *
     * Caranya menuruni jalur ruas demi ruas dari akar proyek, dan pada setiap
     * ruas menuntut namanya muncul PERSIS di dalam daftar isi induknya. Itu
     * cara yang sama dengan yang dilakukan Linux ketika membuka jalur.
     */
    private function existsCaseSensitively(string $path): bool
    {
        $base = base_path();

        if (! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return false;
        }

        $current = $base;

        foreach (explode(DIRECTORY_SEPARATOR, substr($path, strlen($base) + 1)) as $segment) {
            $entries = is_dir($current) ? scandir($current) : false;

            if ($entries === false || ! in_array($segment, $entries, true)) {
                return false;
            }

            $current .= DIRECTORY_SEPARATOR.$segment;
        }

        return true;
    }
}
