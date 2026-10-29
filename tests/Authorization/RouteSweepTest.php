<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * SAPUAN OTORISASI SELURUH RUTE — docs/09-testing-qa.md §4.3.
 *
 * MENGAPA UJI INI ADA
 * -------------------
 * docs/09 §4.3 mewajibkan uji otorisasi yang DIBANGKITKAN SECARA TERPROGRAM:
 * setiap peran × setiap endpoint. Alasannya ada di kalimat berikutnya pada
 * dokumen itu — dengan MySQL tidak ada Row-Level Security (KEP-002), sehingga
 * pembatasan akses sepenuhnya ditegakkan kode, dan kegagalan lapis ini
 * MEMBLOKIR RILIS.
 *
 * Uji yang ditulis tangan per layar tidak dapat memenuhi itu: ia hanya
 * menjaga layar yang sempat diingat seseorang. Rute yang ditambahkan enam
 * bulan lagi — oleh orang yang tidak pernah membaca dokumen ini — tidak akan
 * punya ujinya, dan justru rute itulah yang paling mungkin lupa memasang
 * pemeriksaan izin.
 *
 * Karena itu daftar rutenya DIBACA DARI TABEL RUTE, bukan ditulis ulang di
 * sini. Menambah rute baru tanpa izin akan menggagalkan uji ini dengan
 * sendirinya.
 *
 * JIKA UJI INI GAGAL: jangan menambahkan pengecualian tanpa alasan tertulis.
 * Pasang `->middleware('izin:...')` pada rutenya.
 */
final class RouteSweepTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Izin disemai oleh uji ini SENDIRI, dan itu bukan kehati-hatian
     * berlebihan.
     *
     * Uji ini membandingkan izin yang dirujuk rute dengan isi tabel
     * `permissions`. Sebelumnya ia mengandalkan tabel itu SUDAH terisi dari
     * luar — dan isinya dihapus oleh uji lain yang memakai `RefreshDatabase`
     * (LetterNumberingConcurrencyTest, ReportRecapQueryTest,
     * AuditChainConcurrencyTest, VolumeSeederTest). Akibatnya uji ini lulus
     * atau gagal menurut APA YANG DIJALANKAN SEBELUMNYA:
     *
     *   - satu jalan penuh berurutan: lulus, karena suite Authorization
     *     berjalan sebelum suite Feature;
     *   - jalan BERIKUTNYA pada basis data yang sama: gagal, dengan daftar
     *     delapan puluh sembilan rute yang terbaca persis seperti kerusakan
     *     otorisasi yang nyata;
     *   - di CI, pada tahap cakupan yang menjalankan ulang seluruh suite
     *     setelah tahap Unit/Feature: gagal, selalu.
     *
     * Gerbang rilis (docs/09 §4.3) yang hasilnya bergantung pada urutan
     * bukanlah gerbang. Menyemai di sini membuat uji ini menjawab satu
     * pertanyaan saja — apakah setiap izin yang dirujuk rute benar-benar
     * terdaftar — tanpa meminjam keadaan dari siapa pun.
     *
     * Penyemaiannya ikut dibatalkan bersama transaksi uji, jadi ia tidak
     * meninggalkan apa pun untuk uji berikutnya.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    /**
     * Rute ber-otentikasi yang memang TIDAK memakai izin granular, beserta
     * alasannya. Daftar ini sengaja pendek dan setiap barisnya harus dapat
     * dipertanggungjawabkan.
     *
     * @var array<string, string>
     */
    private const TANPA_IZIN_SAH = [
        // Dasbor menyesuaikan isinya sendiri per peran: tiap bagiannya null
        // bila bukan kewenangan pengguna (DashboardService).
        'dashboard' => 'isi disaring per bagian, bukan per halaman',

        // Pemasangan 2FA harus dapat dibuka justru oleh pengguna yang BELUM
        // memenuhi syarat 2FA; menuntut izin di sini mengunci mereka keluar.
        'two-factor.setup' => 'prasyarat masuk, bukan fitur',
        'two-factor.confirm' => 'prasyarat masuk, bukan fitur',
        'logout' => 'setiap pengguna berhak keluar',

        // Notifikasi milik penggunanya sendiri; dicari lewat relasi miliknya,
        // sehingga notifikasi orang lain menjadi 404.
        'notifikasi.index' => 'hanya notifikasi milik sendiri',
        'notifikasi.baca' => 'hanya notifikasi milik sendiri',
        'notifikasi.baca-semua' => 'hanya notifikasi milik sendiri',

        // Portal warga dijaga middleware `warga` (EnsureVerifiedCitizen) dan
        // repository berlingkup keluarga — bukan izin granular.
        'warga.beranda' => 'dijaga middleware warga + lingkup keluarga',
        'warga.keluarga' => 'dijaga middleware warga + lingkup keluarga',
        'warga.ajukan' => 'dijaga middleware warga + lingkup keluarga',
        'warga.ajukan.kirim' => 'dijaga middleware warga + lingkup keluarga',
        'warga.pengajuan' => 'dijaga middleware warga + lingkup keluarga',
        'warga.pengajuan.detail' => 'dijaga middleware warga + lingkup keluarga',
        'warga.pengaduan' => 'dijaga middleware warga + lingkup pelapor',
        'warga.pengaduan.detail' => 'dijaga middleware warga + lingkup pelapor',
        'warga.belum-aktif' => 'halaman buntu untuk akun yang belum aktif',
    ];

    /**
     * Jumlah rute minimum yang harus tersapu.
     *
     * MENGAPA ANGKA INI ADA: uji yang dibangkitkan dari tabel rute akan
     * LULUS DENGAN SENDIRINYA bila penyaringnya rusak dan tidak
     * mengembalikan satu rute pun. Kegagalan seperti itu tidak terlihat —
     * layar uji tetap hijau — sementara lapis otorisasi yang docs/09 §4.3
     * sebut "memblokir rilis" sebenarnya sudah berhenti diperiksa.
     *
     * Angkanya sengaja jauh di bawah jumlah sebenarnya: yang dijaga adalah
     * "sapuannya masih berjalan", bukan "jumlah rutenya tidak berubah".
     */
    private const MINIMUM_RUTE_TERSAPU = 20;

    #[Test]
    #[TestDox('Sapuan benar-benar menjangkau rute, bukan lulus karena daftarnya kosong')]
    public function sapuan_tidak_kosong(): void
    {
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_RUTE_TERSAPU,
            count($this->sweepableRoutes()),
            'Penyaring rute rusak: sapuan otorisasi tidak menjangkau apa pun, '
            .'sehingga seluruh uji di kelas ini lulus tanpa memeriksa apa pun.'
        );

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_RUTE_TERSAPU,
            count($this->authenticatedRoutes()),
        );
    }

    #[Test]
    #[TestDox('Setiap rute ber-otentikasi memakai izin granular, atau tercatat alasannya')]
    public function setiap_rute_terotentikasi_memakai_izin(): void
    {
        $pelanggaran = [];

        foreach ($this->authenticatedRoutes() as $route) {
            $name = $route->getName() ?? $route->uri();

            if ($this->permissionsOf($route) !== []) {
                continue;
            }

            if (array_key_exists((string) $name, self::TANPA_IZIN_SAH)) {
                continue;
            }

            $pelanggaran[] = sprintf(
                '%s %s (%s)',
                implode('|', $route->methods()),
                $route->uri(),
                $name,
            );
        }

        $this->assertSame([], $pelanggaran, implode("\n", array_merge(
            ['Rute ber-otentikasi tanpa pemeriksaan izin granular:', ''],
            array_map(fn (string $v): string => '  - '.$v, $pelanggaran),
            [
                '',
                'Menyembunyikan menu di UI adalah kenyamanan, BUKAN kontrol keamanan',
                '(docs/07 §2.4). Pasang ->middleware(\'izin:...\') pada rutenya, atau',
                'tambahkan ke TANPA_IZIN_SAH BESERTA ALASANNYA bila memang tidak perlu.',
            ]
        )));
    }

    #[Test]
    #[TestDox('Izin yang dirujuk rute benar-benar ada di PermissionSeeder')]
    public function izin_yang_dirujuk_rute_memang_ada(): void
    {
        $dikenal = Permission::query()->pluck('code')->all();
        $hilang = [];

        foreach ($this->authenticatedRoutes() as $route) {
            foreach ($this->permissionsOf($route) as $permission) {
                if (! in_array($permission, $dikenal, true)) {
                    $hilang[] = sprintf('%s → %s', $route->getName() ?? $route->uri(), $permission);
                }
            }
        }

        /*
         * Izin yang salah ketik TIDAK pernah dimiliki siapa pun, sehingga
         * rutenya menolak SEMUA orang — termasuk Admin Desa. Gejalanya di
         * lapangan adalah "menunya ada tapi selalu bilang tidak berhak", dan
         * tanpa uji ini tidak ada yang menghubungkannya dengan salah ketik.
         */
        $this->assertSame([], $hilang, implode("\n", array_merge(
            ['Rute merujuk izin yang tidak terdaftar di PermissionSeeder:', ''],
            array_map(fn (string $v): string => '  - '.$v, $hilang),
        )));
    }

    #[Test]
    #[TestDox('Tamu ditolak dari setiap rute ber-otentikasi, tanpa satu pun kebocoran')]
    public function tamu_ditolak_dari_seluruh_rute_terotentikasi(): void
    {
        $bocor = [];

        foreach ($this->sweepableRoutes() as $route) {
            $response = $this->get('/'.ltrim($route->uri(), '/'));

            /*
             * Pada aplikasi web Laravel, middleware `auth` MENGALIHKAN ke
             * halaman masuk alih-alih mengembalikan 401 — docs/09 §4.3
             * menuliskan 401 karena ia disusun untuk API. Yang penting dan
             * yang diuji di sini: tamu TIDAK PERNAH menerima 200.
             */
            if ($response->getStatusCode() === 200) {
                $bocor[] = sprintf('%s (%s)', $route->uri(), $route->getName() ?? '-');
            }
        }

        $this->assertSame([], $bocor, implode("\n", array_merge(
            ['KEBOCORAN: tamu menerima HTTP 200 pada rute ber-otentikasi:', ''],
            array_map(fn (string $v): string => '  - '.$v, $bocor),
        )));
    }

    #[Test]
    #[TestDox('Peran tanpa izin yang diminta rute menerima 403 — bukan 200, bukan 500')]
    public function peran_tanpa_izin_ditolak_403(): void
    {
        $gagal = [];

        foreach ($this->sweepableRoutes() as $route) {
            $permissions = $this->permissionsOf($route);

            if ($permissions === []) {
                continue;
            }

            /*
             * Peran penguji dipilih sebagai peran yang TIDAK memegang satu pun
             * izin yang diminta rute. Bila tidak ada peran seperti itu — yaitu
             * seluruh peran berhak — rutenya memang terbuka bagi semua dan
             * tidak ada yang dapat diuji di sini.
             */
            $peran = $this->roleLacking($permissions);

            if ($peran === null) {
                continue;
            }

            $user = $this->userWithRole($peran);
            $response = $this->actingAs($user)->get('/'.ltrim($route->uri(), '/'));

            if ($response->getStatusCode() !== 403) {
                $gagal[] = sprintf(
                    '%s (%s) — peran %s menerima %d, seharusnya 403 [izin: %s]',
                    $route->uri(),
                    $route->getName() ?? '-',
                    $peran,
                    $response->getStatusCode(),
                    implode(',', $permissions),
                );
            }
        }

        $this->assertSame([], $gagal, implode("\n", array_merge(
            ['Pemeriksaan izin tidak ditegakkan pada rute berikut:', ''],
            array_map(fn (string $v): string => '  - '.$v, $gagal),
            [
                '',
                '200 berarti izinnya tidak diperiksa sama sekali — kebocoran.',
                '500 berarti pemeriksaannya ada tetapi pengendalinya rusak untuk',
                'peran itu, yang di lapangan sama buruknya: petugas melihat galat',
                'dan menyimpulkan sistemnya rusak, bukan bahwa ia tidak berhak.',
            ]
        )));
    }

    /* ============================== Pembantu ============================== */

    /**
     * Seluruh rute yang berada di belakang middleware `auth`.
     *
     * @return list<RouteDefinition>
     */
    private function authenticatedRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('auth', $route->gatherMiddleware(), true)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * Rute yang dapat disapu otomatis: GET, tanpa parameter URL.
     *
     * Rute berparameter sengaja DILUAR sapuan ini — ia menuntut objek nyata,
     * dan "objek milik siapa" adalah pertanyaan yang hanya dapat dijawab uji
     * yang menyiapkan datanya sendiri. Itulah tugas ScopeMatrixTest,
     * PopulationHttpScopeTest, ComplaintPanelTest, dan ReportTest — yang
     * masing-masing menguji skenario kepemilikan docs/09 §4.3 (objek sendiri,
     * wilayah lain, keluarga lain, tidak ada).
     *
     * @return list<RouteDefinition>
     */
    private function sweepableRoutes(): array
    {
        return array_values(array_filter(
            $this->authenticatedRoutes(),
            fn (RouteDefinition $route): bool => in_array('GET', $route->methods(), true)
                && ! str_contains($route->uri(), '{'),
        ));
    }

    /**
     * Izin yang diminta sebuah rute, dibaca dari middleware `izin:`.
     *
     * @return list<string>
     */
    private function permissionsOf(RouteDefinition $route): array
    {
        $permissions = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'izin:')) {
                foreach (explode(',', substr($middleware, 5)) as $permission) {
                    $permissions[] = trim($permission);
                }
            }
        }

        return $permissions;
    }

    /**
     * Satu peran yang tidak memegang satu pun izin yang diberikan.
     *
     * @param  list<string>  $permissions
     */
    private function roleLacking(array $permissions): ?string
    {
        static $byRole = null;

        if ($byRole === null) {
            $byRole = [];

            foreach (Role::query()->with('permissions')->get() as $role) {
                $byRole[$role->code] = $role->permissions->pluck('code')->all();
            }
        }

        foreach ($byRole as $code => $held) {
            if (array_intersect($permissions, $held) === []) {
                return (string) $code;
            }
        }

        return null;
    }
}
