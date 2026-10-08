<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * PENJAGA BATAS LAPIS REPOSITORY — docs/04-arsitektur.md §4.1, KEP-002.
 *
 * MENGAPA UJI INI ADA
 * -------------------
 * PostgreSQL punya Row-Level Security sebagai jaring pengaman terakhir;
 * MySQL tidak (KEP-002). Konsekuensinya: SATU kueri yang lupa menyaring
 * wilayah/keluarga akan membocorkan NIK seluruh warga desa, dan tidak ada
 * apa pun di basis data yang akan mencegahnya.
 *
 * Uji ini menegakkan aturan arsitektural bahwa tabel sensitif HANYA boleh
 * diakses lewat App\Repositories — sehingga penyaringan tidak mungkin
 * terlupakan. Ini bukan uji gaya penulisan kode; ini kontrol keamanan.
 *
 * JIKA UJI INI GAGAL: jangan menambahkan pengecualian. Pindahkan kuerinya ke
 * repository yang sesuai.
 */
final class RepositoryBoundaryTest extends TestCase
{
    /** Model yang mewakili tabel dijaga (config/sid.php guarded_tables). */
    private const GUARDED_MODELS = ['Resident', 'Family'];

    /** Direktori yang DILARANG mengakses tabel dijaga secara langsung. */
    private const RESTRICTED_DIRS = [
        'app/Http/Controllers',
        'app/Console/Commands',
        'app/Jobs',
        'app/Services',
    ];

    /** Pengecualian sah, beserta alasannya. */
    private const ALLOWED_FILES = [
        // Seeder data contoh bukan jalur permintaan pengguna.
        'database/seeders/DemoDataSeeder.php',
    ];

    #[Test]
    public function tabel_dijaga_tidak_diakses_langsung_dari_luar_repository(): void
    {
        $violations = [];

        foreach (self::RESTRICTED_DIRS as $dir) {
            foreach ($this->phpFilesIn($dir) as $file) {
                $relative = $this->relativePath($file);

                if (in_array($relative, self::ALLOWED_FILES, true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach (self::GUARDED_MODELS as $model) {
                    foreach ($this->forbiddenPatterns($model) as $pattern => $label) {
                        if (preg_match($pattern, $contents)) {
                            $violations[] = sprintf('%s — %s', $relative, $label);
                        }
                    }
                }

                // DB::table('residents') dan sejenisnya juga melewati repository.
                foreach (['residents', 'families', 'letter_requests', 'letters', 'complaints'] as $table) {
                    if (preg_match('/DB::table\(\s*[\'"]'.$table.'[\'"]/', $contents)) {
                        $violations[] = sprintf(
                            '%s — DB::table(\'%s\') melewati lapis repository', $relative, $table
                        );
                    }
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['Pelanggaran batas lapis repository ditemukan:', ''],
            array_map(fn ($v) => '  - '.$v, $violations),
            [
                '',
                'Tabel ini memuat data pribadi warga dan tidak punya Row-Level',
                'Security (KEP-002). Pindahkan kueri ke App\Repositories agar',
                'pembatasan wilayah & keluarga tidak dapat terlupakan.',
                'Lihat docs/04-arsitektur.md §4.1.',
            ]
        )));
    }

    #[Test]
    public function setiap_repository_mengimplementasikan_apply_scope(): void
    {
        $missing = [];

        foreach ($this->phpFilesIn('app/Repositories') as $file) {
            $name = $file->getBasename('.php');

            if ($name === 'GuardedRepository') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, 'protected function applyScope(')) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Repository berikut tidak mengimplementasikan applyScope(): %s\n".
            'Tanpa itu, repository akan mengembalikan data tanpa pembatasan.',
            implode(', ', $missing)
        ));
    }

    #[Test]
    public function controller_tidak_membaca_nik_tanpa_mencatat_audit(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn('app/Http/Controllers') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (str_contains($contents, 'revealNik()')
                && ! str_contains($contents, 'logPiiReveal')) {
                $violations[] = $this->relativePath($file);
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['Pengungkapan NIK tanpa pencatatan audit log (RULE-07):', ''],
            array_map(fn ($v) => '  - '.$v, $violations),
            ['', 'Setiap pemanggilan revealNik() WAJIB disertai AuditLogger::logPiiReveal().']
        )));
    }

    #[Test]
    public function controller_tidak_menyuntik_dependensi_berlingkup_lewat_konstruktor(): void
    {
        /*
         * MENGAPA UJI INI ADA — ini bug nyata, bukan kehati-hatian berlebihan.
         *
         * Illuminate\Routing\Route::getController() MENYIMPAN instance
         * controller pada objek Route, dan objek Route hidup selama proses
         * berjalan. Dependensi yang di-inject lewat konstruktor karena itu
         * hanya dibuat SEKALI per proses.
         *
         * Untuk repository/service yang membawa AccessScope, akibatnya:
         * lingkup akses pengguna PERTAMA ikut terpakai oleh pengguna
         * berikutnya. Di PHP-FPM tidak terlihat (satu proses satu permintaan);
         * di Laravel Octane pasti terjadi, dan berupa kebocoran data pribadi
         * antar-pengguna.
         *
         * Ditemukan pada Sprint 2 ketika tiga peran berbeda, diminta berurutan,
         * mengembalikan daftar penduduk yang sama persis.
         *
         * Solusinya: inject per METODE. Laravel menyelesaikan parameter metode
         * pada setiap dispatch.
         */
        $scopedTypes = [
            'ResidentRepository', 'FamilyRepository',
            'ResidentService', 'FamilyService', 'RegionService',
            'AccessScope',
        ];

        $violations = [];

        foreach ($this->phpFilesIn('app/Http/Controllers') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (! preg_match('/function\s+__construct\s*\((.*?)\)\s*[{:]/s', $contents, $m)) {
                continue;
            }

            foreach ($scopedTypes as $type) {
                if (str_contains($m[1], $type)) {
                    $violations[] = sprintf(
                        '%s — %s di-inject lewat konstruktor',
                        $this->relativePath($file), $type
                    );
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['Dependensi ber-lingkup akses di-inject lewat konstruktor controller:', ''],
            array_map(fn ($v) => '  - '.$v, $violations),
            [
                '',
                'Route menyimpan instance controller selama proses hidup, sehingga',
                'dependensi ini hanya dibuat SEKALI — membawa lingkup akses pengguna',
                'pertama ke pengguna berikutnya. Pindahkan ke parameter METODE.',
            ]
        )));
    }

    /** @return list<SplFileInfo> */
    private function phpFilesIn(string $relativeDir): array
    {
        $path = $this->basePath().'/'.$relativeDir;

        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return array<string, string> pola regex => label pelanggaran */
    private function forbiddenPatterns(string $model): array
    {
        return [
            '/\b'.$model.'::(query|where|find|all|first|create|with)\s*\(/' => "{$model}:: dipanggil langsung",
            '/new\s+'.$model.'\s*\(\s*\)\s*->\s*(query|where|newQuery)/' => "query {$model} dibangun langsung",
        ];
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relativePath(SplFileInfo $file): string
    {
        return str_replace($this->basePath().'/', '', $file->getPathname());
    }
}
