<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Auth\AccessScope;
use App\Models\Family;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Services\Complaint\ComplaintService;
use App\Services\Letter\LetterRequestService;
use App\Support\NameNormalizer;
use App\Support\VillageTime;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Dashboard — FR-LAP-01, FR-LAP-02, UC-17, docs/07 §5.4.
 *
 * Yang dijaga kelas ini:
 *   1. Bagian yang TIDAK BOLEH dilihat bernilai `null`, bukan nol. Kartu
 *      "Pengaduan terbuka: 0" bagi orang yang memang tidak boleh melihat
 *      pengaduan adalah pernyataan yang salah — dan di dashboard pimpinan
 *      kesalahan seperti itu berakhir sebagai keputusan.
 *   2. "Perlu tindakan Anda" benar-benar menyaring menurut kewenangan tahap,
 *      bukan menampilkan seluruh antrean kepada siapa pun.
 *   3. Angka dibatasi lingkup: Kepala Dusun melihat angka dusunnya.
 *   4. Tren bulanan dikelompokkan pada kalender desa (KEP-010).
 */
final class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rtKrajan;

    private Region $rtSidomulyo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $this->rtKrajan = $this->firstRtOf('Dusun Krajan');
        $this->rtSidomulyo = $this->firstRtOf('Dusun Sidomulyo');
    }

    /* =========================== Hak melihat ============================= */

    #[Test]
    #[TestDox('Bagian yang bukan kewenangan pengguna bernilai null, bukan nol')]
    public function bagian_tanpa_izin_bernilai_null(): void
    {
        $warga = $this->userWithRole(Role::WARGA);

        $this->actingAs($warga)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                /*
                 * Warga memegang `pengaduan.lihat` dan `surat.lihat` (matriks
                 * RBAC "C,R*"), jadi kartunya memang ada — dan isinya terbatas
                 * pada miliknya sendiri lewat lingkup repository.
                 *
                 * Yang TIDAK ia pegang adalah `laporan.lihat` dan
                 * `statistik.lihat`: angka kinerja desa dan komposisi penduduk
                 * bukan haknya, dan keduanya harus null — bukan nol persen.
                 */
                ->where('tren', null)
                ->where('komposisi', null)
                ->where('perlu_tindakan', null)
                /*
                 * Daftar pengaduan terbuka juga null: setiap barisnya
                 * menautkan ke layar PETUGAS, yang sejak Sprint 7 menolak
                 * pengguna berlingkup keluarga. Kartu ANGKA-nya tetap ada —
                 * "berapa pengaduan saya yang masih terbuka" sah baginya.
                 */
                ->where('pengaduan_terbuka', null)
                ->has('pengaduan'));
    }

    #[Test]
    #[TestDox('Admin Desa melihat seluruh bagian dashboard')]
    public function admin_melihat_seluruhnya(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('kependudukan')
                ->has('layanan')
                ->has('pengaduan')
                ->has('tren.bulanan')
                ->has('tren.sla')
                ->has('perlu_tindakan')
                ->has('pengaduan_terbuka')
                ->has('komposisi'));
    }

    #[Test]
    #[TestDox('Tiga gerbang berbeda: surat.lihat, laporan.lihat, dan statistik.lihat masing-masing berdiri sendiri')]
    public function setiap_bagian_punya_gerbang_sendiri(): void
    {
        /*
         * Kepala Dusun melayani surat di wilayahnya (`surat.lihat`) tetapi
         * tidak memegang `laporan.lihat`: angka "tepat waktu 72%" adalah
         * ukuran KINERJA perangkat desa, dibaca pimpinan dan pengawas — bukan
         * data layanan yang ia perlukan untuk bekerja.
         */
        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->regionNamed('Dusun Krajan'));

        $this->assertTrue($kadus->hasPermission('surat.lihat'));
        $this->assertFalse($kadus->hasPermission('laporan.lihat'));

        $this->actingAs($kadus)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('layanan')
                ->where('tren', null)
                ->where('komposisi', null));

        /*
         * Kasi Kesejahteraan memegang `laporan.lihat` TETAPI tidak
         * `statistik.lihat`. Ia karena itu melihat tren layanan tanpa melihat
         * komposisi penduduk — bukti bahwa kedua gerbang memang berdiri
         * sendiri dan bukan satu izin yang dipakai ulang.
         */
        $kesra = $this->userWithRole(Role::KASI_KESEJAHTERAAN);

        $this->assertTrue($kesra->hasPermission('laporan.lihat'));
        $this->assertFalse($kesra->hasPermission('statistik.lihat'));

        $this->actingAs($kesra)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tren.sla')
                ->where('komposisi', null));
    }

    /* ======================= Perlu tindakan saya ========================= */

    #[Test]
    #[TestDox('FR-LAP-02: hanya pengajuan yang menunggu kewenangan pengguna ini yang muncul')]
    public function perlu_tindakan_disaring_menurut_kewenangan(): void
    {
        $domisili = LetterType::query()->where('code', 'SKD')->firstOrFail();
        $pemohon = $this->makeResident('3201010101900111', 'Warga Krajan', $this->rtKrajan);

        $operator = $this->userWithRole(Role::OPERATOR);

        /*
         * actingAs lebih dulu: repository di dalam LetterRequestService
         * disuntik dari container, dan AccessScope-nya dihitung dari pengguna
         * yang sedang masuk (SidServiceProvider, sengaja transient). Tanpa
         * ini, layanan berjalan dengan lingkup TAMU dan pemohonnya tidak
         * terlihat — 404, bukan galat yang menjelaskan dirinya.
         */
        $this->actingAs($operator);

        $pengajuan = app(LetterRequestService::class)->create(
            AccessScope::forUser($operator),
            $domisili,
            $pemohon->id,
            'Keperluan sekolah',
            ['keperluan' => 'Keperluan sekolah'],
            channel: 'walk_in',
        );

        $peranTahap = $domisili->rolesForStep(0);
        $this->assertNotSame([], $peranTahap, 'Jenis surat uji harus punya peran pemeriksa.');

        $pemeriksa = $this->userWithRole($peranTahap[0]);

        $this->actingAs($pemeriksa)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('perlu_tindakan', 1)
                ->where('perlu_tindakan.0.ticket_number', $pengajuan->ticket_number));

        /*
         * Peran yang BUKAN pemeriksa tahap ini tidak melihatnya, walau ia
         * punya izin memverifikasi surat pada umumnya. Daftar yang menampilkan
         * seluruh antrean kepada siapa pun membuat frasa "perlu tindakan
         * Anda" kehilangan artinya, dan orang berhenti membacanya.
         */
        $peranLain = array_values(array_diff(
            [Role::KASI_PEMERINTAHAN, Role::KASI_KESEJAHTERAAN, Role::KASI_PELAYANAN],
            $peranTahap,
        ));

        $bukanPemeriksa = $this->userWithRole($peranLain[0]);

        $this->actingAs($bukanPemeriksa)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('perlu_tindakan', 0));
    }

    /* ============================== Lingkup ============================== */

    #[Test]
    #[TestDox('Angka dashboard dibatasi lingkup: Kepala Dusun melihat dusunnya saja')]
    public function angka_dibatasi_lingkup(): void
    {
        $this->makeResident('3201010101900121', 'Penduduk Krajan', $this->rtKrajan);
        $this->makeResident('3201010101900122', 'Penduduk Krajan Dua', $this->rtKrajan);
        $this->makeResident('3201010101900123', 'Penduduk Sidomulyo', $this->rtSidomulyo);

        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->regionNamed('Dusun Krajan'));

        $this->actingAs($kadus)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kependudukan.penduduk_aktif', 2));

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kependudukan.penduduk_aktif', 3));
    }

    /* ============================ Pengaduan ============================== */

    #[Test]
    #[TestDox('Pengaduan terlambat didahulukan di daftar dashboard, bukan yang terbaru')]
    public function pengaduan_terlambat_didahulukan(): void
    {
        $warga = $this->userWithRole(Role::WARGA);
        $service = app(ComplaintService::class);

        $lama = $service->create(AccessScope::forUser($warga), [
            'category_code' => 'infrastruktur',
            'title' => 'Jalan rusak sejak lama',
            'body' => 'Sudah berbulan-bulan tidak ada perbaikan di jalan dusun.',
        ]);
        $lama->forceFill(['triage_due_at' => now()->subWeeks(2)])->save();

        $service->create(AccessScope::forUser($warga), [
            'category_code' => 'lingkungan',
            'title' => 'Sampah menumpuk kemarin',
            'body' => 'Sampah di tepi saluran air belum diangkut sejak kemarin.',
        ]);

        $kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);

        $this->actingAs($kasi)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pengaduan_terbuka', 2)
                // Yang terlambat di baris pertama, bukan yang paling baru.
                ->where('pengaduan_terbuka.0.title', 'Jalan rusak sejak lama')
                ->where('pengaduan_terbuka.0.is_overdue', true)
                ->where('pengaduan.terlambat', 1));
    }

    #[Test]
    #[TestDox('Kode pelacakan tidak ikut ke dashboard')]
    public function kode_pelacakan_tidak_di_dashboard(): void
    {
        $warga = $this->userWithRole(Role::WARGA);
        $pengaduan = app(ComplaintService::class)->create(AccessScope::forUser($warga), [
            'category_code' => 'infrastruktur',
            'title' => 'Lampu jalan mati di RT 002',
            'body' => 'Sudah dua bulan lampu jalan di depan pos ronda mati.',
        ]);

        $this->actingAs($this->userWithRole(Role::KASI_PEMERINTAHAN))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee($pengaduan->tracking_code, false);
    }

    /* ============================== Tren ================================= */

    #[Test]
    #[TestDox('KEP-010: tren bulanan mengelompokkan pada kalender desa, bukan UTC')]
    public function tren_dikelompokkan_pada_kalender_desa(): void
    {
        $domisili = LetterType::query()->where('code', 'SKD')->firstOrFail();
        $pemohon = $this->makeResident('3201010101900131', 'Warga Subuh', $this->rtKrajan);
        $operator = $this->userWithRole(Role::OPERATOR);
        $this->actingAs($operator);

        /*
         * Pengajuan dibuat pukul 06.00 WIB tanggal 1 — yaitu 23.00 UTC pada
         * tanggal TERAKHIR bulan sebelumnya. Dikelompokkan mentah di UTC, ia
         * jatuh di bulan yang salah, dan grafik tren desa akan selalu meleset
         * setiap awal bulan.
         */
        $subuhTanggalSatu = VillageTime::local(now())
            ->startOfMonth()->setTime(6, 0)->utc();

        $this->travelTo($subuhTanggalSatu);

        app(LetterRequestService::class)->create(
            AccessScope::forUser($operator),
            $domisili,
            $pemohon->id,
            'Keperluan sekolah',
            ['keperluan' => 'Keperluan sekolah'],
            channel: 'walk_in',
        );

        $this->travelBack();

        $bulanIni = VillageTime::local(now())->format('Y-m');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($bulanIni): void {
                $bulanan = $page->toArray()['props']['tren']['bulanan'];

                $this->assertCount(12, $bulanan, 'Tren harus selalu 12 bulan, termasuk yang kosong.');

                $baris = array_values(array_filter(
                    $bulanan,
                    fn (array $b): bool => $b['bulan'] === $bulanIni,
                ));

                $this->assertSame(1, $baris[0]['masuk'] ?? 0);
            });
    }

    /* ============================== Pembantu ============================== */

    private function regionNamed(string $name): Region
    {
        return Region::query()->where('name', $name)->firstOrFail();
    }

    private function firstRtOf(string $dusun): Region
    {
        $rw = Region::query()->where('parent_id', $this->regionNamed($dusun)->id)->firstOrFail();

        return Region::query()->where('parent_id', $rw->id)->firstOrFail();
    }

    private function makeResident(string $nik, string $name, Region $region): Resident
    {
        $family = new Family;
        $family->setKkNumber('320101'.substr($nik, -10));
        $family->forceFill(['region_id' => $region->id, 'address' => 'Alamat Uji'])->save();

        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $region->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1990-01-01',
            'gender' => 'L',
            'family_relation_code' => 'kepala_keluarga',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }
}
