<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\Family;
use App\Models\LetterType;
use App\Models\Official;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Services\Population\StatisticsService;
use App\Support\NameNormalizer;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Portal publik — FR-PRT-01, FR-PRT-04, FR-PRT-05, FR-PRT-08, BR-20..24.
 *
 * Yang dijaga kelas ini bukan hanya "halamannya terbuka", tetapi tiga hal yang
 * membuat portal tanpa otentikasi boleh ada sama sekali:
 *
 *   1. Tidak ada data pribadi yang keluar — termasuk lewat halaman statistik
 *      dan halaman perangkat desa (BR-21, RULE-07).
 *   2. Penyamaran sel kecil tidak dapat dimatikan dari luar (ADR-008).
 *   3. Yang boleh terindeks mesin pencari memang hanya portal, bukan panel
 *      administrasi maupun halaman pelacakan (FR-PRT-08).
 */
final class PublicPortalTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rt;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000007701');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Portal 1',
        ])->save();
    }

    /* =========================== Halaman terbuka ========================== */

    #[Test]
    #[TestDox('Seluruh halaman portal terbuka tanpa login')]
    public function halaman_portal_dapat_dibuka_anonim(): void
    {
        foreach ([
            '/', '/profil', '/profil/wilayah', '/profil/pemerintahan',
            '/layanan', '/statistik', '/kontak',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    #[Test]
    #[TestDox('Beranda memuat nama desa dan dua tindakan utama (docs/07 §5.5)')]
    public function beranda_menampilkan_tindakan_utama(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Desa Sukamaju', false)
            ->assertSee('Ajukan Surat', false);

        /*
         * Tautan pelacakan WAJIB ada di halaman depan. Sebelum portal
         * dibangun, halaman itu hanya dapat dibuka oleh yang mengetahui URL-nya
         * — dan menurut KEP-014 §5 ia tetap hidup sebagai "cek resi" tanpa
         * login bahkan setelah akun warga tersedia.
         */
        $response->assertSee('/surat/lacak', false);
    }

    #[Test]
    #[TestDox('Portal dirender server (Blade), bukan Inertia — tetap terbaca tanpa JavaScript')]
    public function portal_dirender_tanpa_javascript(): void
    {
        $body = (string) $this->get('/')->getContent();

        /*
         * `data-page` adalah wadah tempat Inertia menitipkan seluruh halaman
         * untuk dirender peramban. Kehadirannya di portal berarti halaman ini
         * kosong bagi pengunjung yang berkas JS-nya gagal dimuat — keadaan yang
         * dilarang docs/07 §7 dan lumrah di jaringan desa (KEP-016).
         */
        $this->assertStringNotContainsString('data-page=', $body,
            'Portal publik dirender Inertia; isinya akan hilang tanpa JavaScript.');

        // Isi yang sesungguhnya sudah ada di HTML pertama.
        $this->assertStringContainsString('Desa dalam Angka', $body);
    }

    /* ============================ Standar layanan ========================= */

    #[Test]
    #[TestDox('Halaman layanan menampilkan syarat, biaya, dan lama penyelesaian (BR-23)')]
    public function halaman_layanan_menampilkan_standar_pelayanan(): void
    {
        $sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();

        $this->get('/layanan')
            ->assertOk()
            ->assertSee($sktm->name, false)
            ->assertSee($sktm->sla_days.' hari kerja', false);

        $this->get('/layanan/SKTM')
            ->assertOk()
            ->assertSee('Syarat yang harus disiapkan', false)
            ->assertSee('Fotokopi Kartu Keluarga', false)
            ->assertSee('Gratis', false);
    }

    #[Test]
    #[TestDox('Jenis surat nonaktif tidak muncul di portal dan detailnya 404')]
    public function jenis_surat_nonaktif_tidak_dipublikasikan(): void
    {
        $nonaktif = LetterType::query()->where('is_active', false)->firstOrFail();

        $this->get('/layanan')->assertOk()->assertDontSee($nonaktif->name, false);

        /*
         * 404, bukan 403 (docs/06 §4). 403 akan menyatakan bahwa jenis surat
         * itu memang ada dan sengaja ditutup; bagi warga keduanya berarti sama:
         * tidak dilayani.
         */
        $this->get('/layanan/'.$nonaktif->code)->assertNotFound();
    }

    #[Test]
    #[TestDox('Jenis surat yang hanya dilayani loket tidak dipublikasikan sebagai layanan daring')]
    public function jenis_surat_bukan_publik_tidak_dipublikasikan(): void
    {
        $sku = LetterType::query()->where('code', 'SKU')->firstOrFail();
        $sku->forceFill(['is_public' => false])->save();

        $this->get('/layanan')->assertOk()->assertDontSee($sku->name, false);
        $this->get('/layanan/SKU')->assertNotFound();
    }

    /* ============================== Statistik ============================= */

    #[Test]
    #[TestDox('BR-21: statistik publik tidak memuat nama maupun NIK')]
    public function statistik_publik_tanpa_data_pribadi(): void
    {
        $this->makeResident('3201010101600401', 'Rahasia Portal');
        app(StatisticsService::class)->refresh();

        $body = (string) $this->get('/statistik')->assertOk()->getContent();

        $this->assertStringNotContainsString('Rahasia Portal', $body,
            'Halaman statistik publik memuat nama penduduk.');
        $this->assertStringNotContainsString('3201010101600401', $body,
            'Halaman statistik publik memuat NIK.');
    }

    #[Test]
    #[TestDox('ADR-008: sel < 5 orang disamarkan, dan tidak dapat dimatikan dari query string')]
    public function penyamaran_sel_kecil_tidak_dapat_dimatikan(): void
    {
        // Tiga orang: di bawah ambang penyamaran (config sid.stats_small_cell_threshold).
        $this->makeResident('3201010101600402', 'Satu Portal');
        $this->makeResident('3201010101600403', 'Dua Portal', relation: 'anak');
        $this->makeResident('3201010101600404', 'Tiga Portal', relation: 'anak');
        app(StatisticsService::class)->refresh();

        /*
         * Pada layar petugas berizin laporan, penyamaran justru DIMATIKAN
         * (StatisticsController). Karena itu yang diuji di sini bukan hanya
         * keadaan bawaannya, tetapi bahwa penandanya tidak dapat diturunkan
         * dari permintaan — kalau bisa, siapa pun dapat mematikannya.
         */
        foreach (['/statistik', '/statistik?suppress=0', '/statistik?suppressSmallCells=false'] as $url) {
            $body = (string) $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('&lt; 5', $body,
                "Sel kecil tidak disamarkan pada {$url}.");
        }
    }

    #[Test]
    #[TestDox('Statistik publik tidak menerima saringan per wilayah (agregat per RT dapat dipersempit sampai satu orang)')]
    public function statistik_publik_tidak_menerima_saringan_wilayah(): void
    {
        $this->makeResident('3201010101600405', 'Warga Krajan');
        app(StatisticsService::class)->refresh();

        $tanpaSaringan = (string) $this->get('/statistik')->assertOk()->getContent();
        $denganSaringan = (string) $this->get('/statistik?region_id='.$this->rt->id)
            ->assertOk()->getContent();

        /*
         * Keduanya harus menghasilkan halaman yang sama: saringan diabaikan,
         * bukan diterapkan lalu disamarkan. Rincian per wilayah tetap ada —
         * di panel petugas yang berizin (FR-PDK-15).
         */
        $this->assertSame(
            $this->jumlahJiwaTertulis($tanpaSaringan),
            $this->jumlahJiwaTertulis($denganSaringan),
            'Halaman statistik publik menerapkan saringan wilayah dari query string.'
        );
    }

    /* ============================ Perangkat desa ========================== */

    #[Test]
    #[TestDox('Halaman pemerintahan menampilkan jabatan, tetapi tidak NIP maupun tanda tangan')]
    public function halaman_pemerintahan_tidak_membuka_data_kepegawaian(): void
    {
        $official = Official::query()->create([
            'name' => 'Sutarno Hadi',
            'position' => 'Kepala Desa',
            'position_code' => 'kepala_desa',
            'nip' => '198001012005011001',
            'signature_path' => 'ttd/kades.png',
            'can_sign_letter' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $body = (string) $this->get('/profil/pemerintahan')->assertOk()->getContent();

        $this->assertStringContainsString('Sutarno Hadi', $body);
        $this->assertStringContainsString('Kepala Desa', $body);

        /*
         * NIP adalah pengenal kepegawaian, dan gambar tanda tangan yang beredar
         * bebas dapat ditempelkan ke dokumen mana pun — padahal gambar itulah
         * yang dipakai mencetak surat desa (docs/08 §4).
         */
        $this->assertStringNotContainsString($official->nip, $body,
            'NIP perangkat desa terbuka di portal publik.');
        $this->assertStringNotContainsString('ttd/kades.png', $body,
            'Berkas tanda tangan perangkat desa terbuka di portal publik.');
    }

    /* ======================== Angka agregat se-desa ======================= */

    #[Test]
    #[TestDox('Pengunjung anonim melihat jumlah keluarga se-desa yang sebenarnya, bukan nol')]
    public function angka_keluarga_tidak_nol_bagi_anonim(): void
    {
        $this->makeResident('3201010101600406', 'Kepala Portal');
        app(StatisticsService::class)->refresh();

        /*
         * FamilyRepository::countAll() berlingkup, dan pengunjung anonim tidak
         * punya AccessScope sama sekali — dipakai di sini, desa menerbitkan
         * angka 0 di halaman depannya sendiri. Karena itu portal memakai
         * countAllIgnoringScope(), yang hanya mengembalikan satu bilangan
         * se-desa dan tidak dapat menghasilkan satu baris pun.
         */
        $this->get('/')->assertOk()->assertSee('Keluarga', false);

        $body = (string) $this->get('/statistik')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/Keluarga<\/dt>\s*<dd[^>]*>1<\/dd>/',
            $body,
            'Jumlah keluarga di portal tidak terbaca sebagai 1 — lingkup akses '
            .'anonim ikut menyaring angka agregat.'
        );
    }

    /* ========================= SEO: FR-PRT-08, BR-24 ====================== */

    #[Test]
    #[TestDox('FR-PRT-08: portal boleh terindeks, lengkap dengan canonical, Open Graph & data terstruktur')]
    public function portal_dapat_terindeks_dan_punya_metadata(): void
    {
        $body = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('name="robots" content="index, follow"', $body);
        $this->assertStringContainsString('rel="canonical"', $body);
        $this->assertStringContainsString('property="og:title"', $body);
        $this->assertStringContainsString('GovernmentOrganization', $body);
    }

    #[Test]
    #[TestDox('Panel administrasi tetap tidak boleh terindeks')]
    public function panel_administrasi_tetap_noindex(): void
    {
        $body = (string) $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('noindex', $body,
            'Panel administrasi kehilangan penanda noindex.');
    }

    #[Test]
    #[TestDox('sitemap.xml memuat halaman portal & layanan aktif, tanpa halaman internal')]
    public function sitemap_hanya_memuat_halaman_publik(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = (string) $response->getContent();

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString(url('/layanan/SKTM'), $xml);
        $this->assertStringContainsString(url('/statistik'), $xml);

        /*
         * Pelacakan dan verifikasi tidak berarti apa pun tanpa nomor tiket atau
         * kode verifikasi; mengindeksnya hanya menyebarkan URL yang akan
         * dicoba-coba (KEP-014 §2).
         */
        $this->assertStringNotContainsString('/surat/lacak', $xml);
        $this->assertStringNotContainsString('/dashboard', $xml);
        $this->assertStringNotContainsString('/kependudukan', $xml);
    }

    #[Test]
    #[TestDox('robots.txt menutup panel administrasi dan menunjuk peta situs dengan URL absolut')]
    public function robots_menutup_panel_dan_menunjuk_sitemap(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();

        $isi = (string) $response->getContent();

        $this->assertStringContainsString('Disallow: /kependudukan', $isi);
        $this->assertStringContainsString('Disallow: /surat', $isi);

        // URL absolut: berkas statis di public/ tidak tahu domain desa.
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $isi);
    }

    /* ================================ Bantu =============================== */

    /** Angka pertama pada kartu "Jiwa" — pembanding yang stabil antar halaman. */
    private function jumlahJiwaTertulis(string $html): string
    {
        preg_match('/Jiwa<\/dt>\s*<dd[^>]*>([^<]+)<\/dd>/', $html, $m);

        return trim($m[1] ?? 'tidak ditemukan');
    }

    private function makeResident(
        string $nik,
        string $name,
        string $relation = 'kepala_keluarga',
    ): Resident {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $this->family->id,
            'region_id' => $this->rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1985-06-15',
            'gender' => 'L',
            'family_relation_code' => $relation,
            'religion_code' => 'islam',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }
}
