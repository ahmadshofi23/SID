<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Aplikasi web progresif — docs/03 §2.3, docs/07 §8.
 *
 * YANG PALING DIJAGA KELAS INI bukan "aplikasinya dapat dipasang", melainkan
 * aturan yang membuat pemasangan itu boleh ada: service worker tidak pernah
 * menyimpan HTML maupun data ke perangkat. Ponsel di desa dipakai bergantian,
 * dan halaman daftar penduduk yang tersimpan di cache tetap dapat dibuka
 * setelah penggunanya keluar — tanpa melewati satu pun pemeriksaan izin.
 */
final class PwaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    /* ============================== Manifest ============================= */

    #[Test]
    #[TestDox('Manifest memakai nama desa dari basis data, bukan nama contoh yang dipatok')]
    public function manifest_memakai_nama_desa(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json; charset=UTF-8')
            ->assertJsonPath('name', 'Desa Teluk Sentosa')
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('lang', 'id');
    }

    #[Test]
    #[TestDox('short_name dipotong agar muat di bawah ikon layar utama')]
    public function short_name_dipotong(): void
    {
        $nama = (string) $this->get('/manifest.webmanifest')->assertOk()->json('short_name');

        $this->assertLessThanOrEqual(12, mb_strlen($nama),
            'Nama di bawah ikon akan terpotong peramban menjadi "Desa Teluk…".');
    }

    #[Test]
    #[TestDox('Manifest menyebut ikon maskable terpisah dari ikon biasa')]
    public function manifest_punya_ikon_maskable(): void
    {
        $icons = (array) $this->get('/manifest.webmanifest')->assertOk()->json('icons');

        $purposes = array_column($icons, 'purpose');

        $this->assertContains('any', $purposes);
        $this->assertContains('maskable', $purposes,
            'Tanpa ikon maskable, peluncur Android memotong pilar pada lambangnya.');
    }

    #[Test]
    #[TestDox('Seluruh berkas ikon yang disebut manifest benar-benar ada')]
    public function berkas_ikon_ada(): void
    {
        $icons = (array) $this->get('/manifest.webmanifest')->assertOk()->json('icons');

        foreach ($icons as $icon) {
            $path = public_path(ltrim((string) $icon['src'], '/'));

            $this->assertFileExists($path);

            $ukuran = getimagesize($path);

            $this->assertIsArray($ukuran, 'Berkas ikon bukan gambar yang terbaca.');
            $this->assertSame(
                (string) $icon['sizes'],
                $ukuran[0].'x'.$ukuran[1],
                'Ukuran berkas ikon tidak sesuai dengan yang dijanjikan manifest — '
                .'peramban menolak memasang aplikasi dengan ikon yang tidak cocok.'
            );
        }
    }

    /* ============================ Halaman luring ========================= */

    #[Test]
    #[TestDox('Halaman luring terbuka tanpa login dan tidak memuat data apa pun')]
    public function halaman_luring_tanpa_data(): void
    {
        $body = (string) $this->get('/luring')->assertOk()->getContent();

        $this->assertStringContainsString('Tidak ada koneksi internet', $body);

        /*
         * Halaman ini tersimpan di perangkat sampai versi cache dinaikkan.
         * Apa pun yang ada di dalamnya ikut tersimpan di ponsel yang mungkin
         * dipakai bergantian — termasuk nama desa, yang di sini pun tidak
         * diperlukan.
         */
        $this->assertStringNotContainsString('Teluk Sentosa', $body,
            'Halaman luring memuat data dari basis data; ia tersimpan di perangkat.');

        // Tidak boleh terindeks: isinya tidak berguna bagi siapa pun yang mencari.
        $this->assertStringContainsString('name="robots" content="noindex"', $body);
    }

    #[Test]
    #[TestDox('Halaman luring tidak bergantung pada berkas CSS yang harus diunduh')]
    public function halaman_luring_bergaya_sebaris(): void
    {
        $body = (string) $this->get('/luring')->assertOk()->getContent();

        $this->assertStringContainsString('<style', $body);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $body,
            'Halaman yang menjelaskan ketiadaan koneksi tidak boleh ikut gagal '
            .'karena satu permintaan jaringan.');
    }

    #[Test]
    #[TestDox('Mode gelap halaman luring hanya menimpa variabel warna, bukan aturannya')]
    public function mode_gelap_luring_hanya_variabel(): void
    {
        $body = (string) $this->get('/luring')->assertOk()->getContent();

        $this->assertSame(1, preg_match(
            '/@media \(prefers-color-scheme: dark\) \{(.*?)\n        \}/s', $body, $cocok
        ), 'Blok mode gelap tidak ditemukan.');

        /*
         * Versi pertama halaman ini menaruh aturan .kartu DI DALAM blok mode
         * gelap, lalu mendefinisikan ulang .kartu sesudahnya. Keduanya
         * berspesifisitas sama, jadi yang belakangan menang: kartunya tetap
         * putih sementara teksnya sudah terang, dan halaman praktis tidak
         * terbaca. Ketahuan hanya karena dibuka langsung di peramban.
         *
         * Dengan blok gelap yang HANYA berisi :root, urutan aturan tidak
         * lagi dapat membatalkannya.
         */
        $this->assertStringNotContainsString('.kartu', $cocok[1],
            'Blok mode gelap menimpa aturan, bukan variabel — urutan aturan '
            .'CSS dapat membatalkannya tanpa ada yang menyadari.');
        $this->assertStringNotContainsString('.tombol', $cocok[1]);
        $this->assertStringContainsString(':root', $cocok[1]);
    }

    /* ========================== Service worker =========================== */

    #[Test]
    #[TestDox('Service worker disajikan dari akar agar lingkupnya mencakup seluruh situs')]
    public function service_worker_ada_di_akar(): void
    {
        $this->assertFileExists(public_path('sw.js'),
            'Service worker di luar akar hanya berlaku untuk sebagian URL.');
    }

    #[Test]
    #[TestDox('Service worker TIDAK PERNAH menyimpan HTML: hanya aset ber-hash yang boleh di-cache')]
    public function service_worker_tidak_menyimpan_html(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        /*
         * Daftar awalan yang boleh di-cache diperiksa APA ADANYA.
         *
         * Uji ini akan gagal begitu seseorang menambahkan awalan baru —
         * dan itulah maksudnya. Menambahkan '/surat/' ke daftar itu akan
         * terlihat seperti perbaikan kinerja yang tidak berbahaya, padahal
         * ia menaruh data pengajuan warga di cache ponsel yang dipakai
         * bergantian, di luar jangkauan setiap pemeriksaan izin di server.
         */
        preg_match_all("#pathname\.startsWith\('([^']+)'\)#", $sw, $cocok);

        $this->assertSame(
            ['/build/assets/', '/ikon/'],
            $cocok[1],
            'Daftar jalur yang boleh di-cache berubah. Bila ini disengaja, '
            .'pastikan jalur barunya TIDAK PERNAH memuat data warga, lalu '
            .'perbarui uji ini beserta alasannya.'
        );

        // Perpindahan halaman wajib lewat jaringan, dengan luring sebagai pagar.
        $this->assertStringContainsString("request.mode === 'navigate'", $sw);
        $this->assertStringContainsString('/luring', $sw);

        // Permintaan selain GET tidak boleh disentuh sama sekali.
        $this->assertStringContainsString("request.method !== 'GET'", $sw);
    }

    /* ======================== Penanda di kedua kerangka =================== */

    #[Test]
    #[TestDox('Portal publik dan panel memakai manifest serta ikon yang sama')]
    public function kedua_kerangka_menyertakan_manifest(): void
    {
        $portal = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('rel="manifest"', $portal);
        $this->assertStringContainsString('name="theme-color"', $portal);
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", $portal);

        $panel = (string) $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('rel="manifest"', $panel,
            'Aplikasi terpasang akan menampilkan dua rupa berbeda antara '
            .'halaman depan dan halaman setelah masuk.');
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", $panel);
    }

    #[Test]
    #[TestDox('Skrip pendaftaran menyandang nonce yang sama dengan header CSP')]
    public function skrip_pendaftaran_bernonce(): void
    {
        $response = $this->get('/')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", $csp, $cocok));

        /*
         * Tanpa nonce yang cocok, skrip pendaftaran diblokir CSP tanpa pesan
         * galat selain di konsol — dan aplikasi diam-diam berhenti dapat
         * dipasang, gejala yang tidak akan dihubungkan siapa pun dengan CSP.
         */
        $this->assertMatchesRegularExpression(
            '/<script nonce="'.preg_quote($cocok[1], '/').'">\s*\n\s*\/\*/',
            (string) $response->getContent(),
        );
    }

    #[Test]
    #[TestDox('Portal warga pun menyertakan penanda yang sama')]
    public function portal_warga_menyertakan_manifest(): void
    {
        $warga = $this->makeCitizen();

        $body = (string) $this->actingAs($warga)->get('/warga')->assertOk()->getContent();

        $this->assertStringContainsString('rel="manifest"', $body);
    }

    /* ================================ Bantu =============================== */

    private function makeCitizen(): User
    {
        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $family = new Family;
        $family->setKkNumber('3201010000006601');
        $family->forceFill(['region_id' => $rt->id, 'address' => 'Jl. PWA 1'])->save();

        $resident = new Resident;
        $resident->setNik('3201011007400031');
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $rt->id,
            'full_name' => 'Warga PWA',
            'full_name_norm' => NameNormalizer::normalize('Warga PWA'),
            'birth_date' => '1990-01-01',
            'gender' => 'L',
            'family_relation_code' => 'kepala_keluarga',
            'religion_code' => 'islam',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $this->userWithRole(Role::WARGA, residentId: $resident->id);
    }
}
