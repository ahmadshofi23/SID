<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * TC-SEC-SCOPE-01 lanjutan — pembatasan akses melalui JALUR HTTP NYATA.
 *
 * ScopeMatrixTest menguji lapis repository secara langsung. Uji ini menutup
 * celah berikutnya: apakah pembatasan itu benar-benar berlaku ketika diakses
 * lewat URL, termasuk halaman detail, daftar, pencarian, dan pembukaan NIK.
 *
 * Tanpa Row-Level Security (KEP-002), satu kontroler yang lupa memakai
 * repository akan membocorkan data — dan hanya uji inilah yang menangkapnya.
 */
final class PopulationHttpScopeTest extends TestCase
{
    use DatabaseTransactions;

    private Region $dusunKrajan;

    private Region $dusunSidomulyo;

    private Resident $wargaKrajan;

    private Resident $wargaSidomulyo;

    private Family $kkKrajan;

    private Family $kkSidomulyo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $this->dusunSidomulyo = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        $rtKrajan = $this->firstRt($this->dusunKrajan);
        $rtSidomulyo = $this->firstRt($this->dusunSidomulyo);

        $this->kkKrajan = $this->makeFamily('3201010000008001', $rtKrajan);
        $this->kkSidomulyo = $this->makeFamily('3201010000008002', $rtSidomulyo);

        $this->wargaKrajan = $this->makeResident(
            $this->kkKrajan, $rtKrajan, '3201010101800101', 'Warga Krajan Satu'
        );
        $this->wargaSidomulyo = $this->makeResident(
            $this->kkSidomulyo, $rtSidomulyo, '3201010101800102', 'Warga Sidomulyo Satu'
        );
    }

    #[Test]
    #[TestDox('Kadus membuka detail penduduk dusun lain → 404 (bukan 403)')]
    public function detail_penduduk_dusun_lain_404(): void
    {
        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $this->actingAs($kadus)
            ->get("/kependudukan/penduduk/{$this->wargaKrajan->id}")
            ->assertOk();

        $this->actingAs($kadus)
            ->get("/kependudukan/penduduk/{$this->wargaSidomulyo->id}")
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Daftar penduduk Kadus tidak memuat warga dusun lain')]
    public function daftar_penduduk_tidak_bocor(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan))
            ->get('/kependudukan/penduduk');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page): void {
            $names = collect($page->toArray()['props']['residents']['data'])
                ->pluck('full_name');

            $this->assertTrue($names->contains('Warga Krajan Satu'));
            $this->assertFalse(
                $names->contains('Warga Sidomulyo Satu'),
                'KEBOCORAN DATA: daftar memuat penduduk dusun lain.'
            );
        });
    }

    #[Test]
    #[TestDox('Pencarian nama warga dusun lain tidak mengembalikan hasil')]
    public function pencarian_tidak_bocor(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan))
            ->get('/kependudukan/penduduk?q=Sidomulyo');

        $response->assertOk();
        $response->assertDontSee('Warga Sidomulyo Satu');
    }

    #[Test]
    #[TestDox('Pencarian NIK persis milik dusun lain tidak mengembalikan hasil')]
    public function pencarian_nik_persis_tidak_bocor(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan))
            ->get('/kependudukan/penduduk?q=3201010101800102');

        $response->assertOk();
        $response->assertDontSee('Warga Sidomulyo Satu');
    }

    #[Test]
    #[TestDox('Autocomplete tidak membocorkan penduduk dusun lain')]
    public function autocomplete_tidak_bocor(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan))
            ->getJson('/kependudukan/penduduk/cari?q=Sidomulyo');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    #[Test]
    #[TestDox('Autocomplete tidak pernah mengembalikan NIK penuh')]
    public function autocomplete_tidak_mengembalikan_nik_penuh(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->getJson('/kependudukan/penduduk/cari?q=Warga');

        $response->assertOk();
        $body = $response->getContent() ?: '';

        $this->assertStringNotContainsString('3201010101800101', $body,
            'Autocomplete membocorkan NIK penuh — melewati RULE-07.');

        // JSON meng-escape • sebagai \u2022, jadi diperiksa pada data hasil decode.
        $this->assertStringContainsString('••••', (string) $response->json('data.0.nik'));
    }

    #[Test]
    #[TestDox('Kadus tidak dapat mengubah penduduk dusun lain')]
    public function ubah_penduduk_dusun_lain_404(): void
    {
        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);

        // Kadus memang tidak punya izin ubah sama sekali.
        $this->actingAs($kadus)
            ->get("/kependudukan/penduduk/{$this->wargaSidomulyo->id}/ubah")
            ->assertForbidden();
    }

    #[Test]
    #[TestDox('Kadus tidak dapat membuka detail KK dusun lain')]
    public function detail_kk_dusun_lain_404(): void
    {
        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $this->actingAs($kadus)->get("/kependudukan/keluarga/{$this->kkKrajan->id}")->assertOk();
        $this->actingAs($kadus)->get("/kependudukan/keluarga/{$this->kkSidomulyo->id}")->assertNotFound();
    }

    #[Test]
    #[TestDox('Warga hanya melihat anggota keluarganya sendiri')]
    public function warga_hanya_keluarganya(): void
    {
        $warga = $this->userWithRole(Role::WARGA, null, $this->wargaKrajan->id);

        $this->actingAs($warga)
            ->get("/kependudukan/penduduk/{$this->wargaKrajan->id}")->assertOk();

        $this->actingAs($warga)
            ->get("/kependudukan/penduduk/{$this->wargaSidomulyo->id}")->assertNotFound();
    }

    #[Test]
    #[TestDox('Hanya Admin Desa yang dapat membuka NIK penuh, dan selalu tercatat')]
    public function pembukaan_nik_terbatas_dan_tercatat(): void
    {
        // Operator: punya izin lihat, TIDAK punya data_pribadi.lihat_penuh
        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->postJson("/kependudukan/penduduk/{$this->wargaKrajan->id}/nik")
            ->assertForbidden();

        $admin = $this->userWithRole(Role::ADMIN_DESA);
        $response = $this->actingAs($admin)
            ->postJson("/kependudukan/penduduk/{$this->wargaKrajan->id}/nik");

        $response->assertOk();
        $this->assertSame('3201010101800101', $response->json('nik'));

        // RULE-07: setiap pembukaan WAJIB tercatat.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'data.pii_revealed',
            'entity_id' => $this->wargaKrajan->id,
            'actor_id' => $admin->id,
        ]);
    }

    #[Test]
    #[TestDox('Audit log pembukaan NIK tidak menyimpan NIK-nya sendiri')]
    public function audit_pembukaan_tidak_menyimpan_nik(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->postJson("/kependudukan/penduduk/{$this->wargaKrajan->id}/nik");

        $raw = (string) DB::table('audit_logs')
            ->where('action', 'data.pii_revealed')->latest('id')->value('context');

        $this->assertStringNotContainsString('3201010101800101', $raw,
            'Audit log mencatat NIK-nya — log justru menjadi tempat kebocoran.');
    }

    #[Test]
    #[TestDox('Kadus tidak dapat membuka NIK meski penduduk dusunnya sendiri')]
    public function kadus_tidak_dapat_membuka_nik(): void
    {
        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan))
            ->postJson("/kependudukan/penduduk/{$this->wargaKrajan->id}/nik")
            ->assertForbidden();
    }

    #[Test]
    #[TestDox('Data halaman tidak memuat NIK penuh — hanya bentuk ter-masking')]
    public function data_halaman_tidak_memuat_nik_penuh(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/kependudukan/penduduk/{$this->wargaKrajan->id}");

        $response->assertOk();

        /*
         * Diperiksa pada PROPS Inertia, bukan pada HTML mentah.
         *
         * Inilah yang sebenarnya dikirim ke peramban dan dapat dibaca siapa pun
         * lewat "view source" atau alat pengembang. Bila NIK penuh lolos ke
         * sini, seluruh perlindungan RULE-07 menjadi sia-sia — tombol "lihat"
         * yang tercatat di audit log tidak ada artinya kalau nilainya sudah ada
         * di halaman sejak awal.
         */
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Kependudukan/Penduduk/Show')
                ->where('resident.nik_masked', '3201••••••••0101')
                ->missing('resident.nik')
                ->missing('resident.nik_enc')
        );

        $this->assertStringNotContainsString(
            '3201010101800101',
            (string) $response->getContent(),
            'NIK penuh ikut terkirim ke peramban.'
        );
    }

    #[Test]
    #[TestDox('Objek yang tidak ada mengembalikan 404, bukan galat server')]
    public function objek_tidak_ada_404(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get('/kependudukan/penduduk/018f0000-0000-7000-8000-000000000000')
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('REGRESI: permintaan berurutan oleh peran berbeda tidak berbagi lingkup akses')]
    public function lingkup_tidak_bocor_antar_permintaan_berurutan(): void
    {
        /*
         * REGRESI Sprint 2 — lihat tests/Architecture (konstruktor controller).
         *
         * Bug aslinya: instance controller tersimpan pada objek Route selama
         * proses hidup, sehingga repository beserta AccessScope-nya hanya
         * dibuat sekali. Permintaan kedua dan seterusnya memakai lingkup akses
         * pengguna pertama.
         *
         * Uji ini menirukan satu proses yang melayani beberapa pengguna
         * berturut-turut — persis yang terjadi pada Laravel Octane.
         */
        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $namesFor = function ($user): array {
            $names = [];

            $this->actingAs($user)->get('/kependudukan/penduduk')
                ->assertInertia(function (AssertableInertia $page) use (&$names): void {
                    $names = collect($page->toArray()['props']['residents']['data'])
                        ->pluck('full_name')->sort()->values()->all();
                });

            return $names;
        };

        // Kadus LEBIH DULU: bila lingkup melekat, Admin akan ikut terbatas.
        $kadusNames = $namesFor($kadus);
        $adminNames = $namesFor($admin);

        $this->assertNotContains('Warga Sidomulyo Satu', $kadusNames);
        $this->assertContains(
            'Warga Sidomulyo Satu',
            $adminNames,
            'KEBOCORAN LINGKUP: Admin Desa ikut terbatas pada wilayah Kepala Dusun '
            .'yang mengakses sebelumnya. Dependensi ber-lingkup kemungkinan '
            .'di-inject lewat konstruktor controller.'
        );

        // Arah sebaliknya juga harus benar.
        $this->assertNotContains('Warga Sidomulyo Satu', $namesFor($kadus));
    }

    private function firstRt(Region $dusun): Region
    {
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();

        return Region::query()->where('parent_id', $rw->id)->firstOrFail();
    }

    private function makeFamily(string $kkNumber, Region $region): Family
    {
        $family = new Family;
        $family->setKkNumber($kkNumber);
        $family->forceFill(['region_id' => $region->id, 'address' => 'Alamat Uji'])->save();

        return $family;
    }

    private function makeResident(
        Family $family, Region $region, string $nik, string $name
    ): Resident {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $region->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1980-01-01',
            'gender' => 'L',
            'family_relation_code' => 'kepala_keluarga',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }
}
