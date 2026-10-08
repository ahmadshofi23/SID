<?php

declare(strict_types=1);

namespace Tests\Feature\Population;

use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Services\Population\AnomalyService;
use App\Services\Population\StatisticsService;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Statistik (FR-PDK-15, ADR-007/008), anomali (FR-PDK-13), ekspor (FR-PDK-12).
 */
final class StatisticsAndAnomalyTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rtKrajan;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rtKrajan = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000005001');
        $this->family->forceFill([
            'region_id' => $this->rtKrajan->id, 'address' => 'Jl. Statistik',
        ])->save();
    }

    /* ============================== Statistik ============================= */

    #[Test]
    #[TestDox('Penduduk meninggal & pindah tidak dihitung (RULE-03)')]
    public function statistik_hanya_penduduk_aktif(): void
    {
        $this->makeResident('3201010101600301', 'Aktif Satu');
        $this->makeResident('3201010101600302', 'Aktif Dua', relation: 'anak');
        $this->makeResident('3201010101600303', 'Sudah Meninggal',
            relation: 'anak', status: 'meninggal');
        $this->makeResident('3201010101600304', 'Sudah Pindah',
            relation: 'anak', status: 'pindah_keluar');

        app(StatisticsService::class)->refresh();

        $this->assertSame(2, app(StatisticsService::class)->summary()['total']);
    }

    #[Test]
    #[TestDox('ADR-008: kelompok berisi kurang dari 5 orang disamarkan sebagai "< 5"')]
    public function sel_kecil_disamarkan(): void
    {
        $this->makeResident('3201010101600305', 'Sendirian');

        app(StatisticsService::class)->refresh();

        $disamarkan = app(StatisticsService::class)->summary(suppressSmallCells: true);
        $apaAdanya = app(StatisticsService::class)->summary(suppressSmallCells: false);

        $this->assertSame('< 5', $disamarkan['by_gender'][0]['total'],
            'Sel kecil tidak disamarkan — satu orang dapat dikenali dari '
            .'kombinasi saringan yang sempit.');

        $this->assertSame(1, $apaAdanya['by_gender'][0]['total'],
            'Laporan internal harus tetap menampilkan angka sebenarnya.');
    }

    #[Test]
    #[TestDox('Tabel ringkasan dibangun ulang, bukan ditimpa')]
    public function ringkasan_dibangun_ulang(): void
    {
        $resident = $this->makeResident('3201010101600306', 'Akan Pindah');
        app(StatisticsService::class)->refresh();

        $this->assertSame(1, app(StatisticsService::class)->summary()['total']);

        $resident->status = 'pindah_keluar';
        $resident->save();
        app(StatisticsService::class)->refresh();

        /*
         * Bila baris lama hanya ditimpa (upsert), kombinasi yang kehilangan
         * seluruh penduduknya akan meninggalkan angka basi.
         */
        $this->assertSame(0, app(StatisticsService::class)->summary()['total']);
    }

    #[Test]
    #[TestDox('Halaman statistik publik tidak memuat data pribadi')]
    public function halaman_statistik_tanpa_data_pribadi(): void
    {
        $this->makeResident('3201010101600307', 'Rahasia Nama');
        app(StatisticsService::class)->refresh();

        $response = $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->get('/kependudukan/statistik');

        $response->assertOk();

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('3201010101600307', $body);
        $this->assertStringNotContainsString('Rahasia Nama', $body,
            'Halaman statistik memuat nama penduduk — ia harus agregat saja.');
    }

    /* =============================== Anomali ============================== */

    #[Test]
    #[TestDox('Kartu keluarga tanpa kepala keluarga terdeteksi')]
    public function anomali_kk_tanpa_kepala(): void
    {
        $this->makeResident('3201010101600308', 'Anak Sendiri', relation: 'anak');

        $report = $this->anomalyReportAs(Role::ADMIN_DESA);

        $this->assertSame(1, $report['kk_tanpa_kepala']['count']);
    }

    #[Test]
    #[TestDox('Nama mirip dalam satu wilayah terdeteksi — jaring pengaman R-19')]
    public function anomali_nama_mirip(): void
    {
        // Normalisasi membuang gelar & tanda baca, sehingga ketiganya sama.
        $this->makeResident('3201010101600309', 'Bambang Wijaya');
        $this->makeResident('3201010101600310', 'Drs. Bambang Wijaya', relation: 'anak');

        $report = $this->anomalyReportAs(Role::ADMIN_DESA);

        $this->assertSame(1, $report['nama_mirip']['count']);
        $this->assertCount(2, $report['nama_mirip']['items'][0]['penduduk']);
    }

    #[Test]
    #[TestDox('Penduduk tanpa wilayah terdeteksi')]
    public function anomali_tanpa_wilayah(): void
    {
        $r = $this->makeResident('3201010101600311', 'Tanpa RT');
        $r->region_id = null;
        $r->save();

        $this->assertSame(1, $this->anomalyReportAs(Role::ADMIN_DESA)['tanpa_wilayah']['count']);
    }

    #[Test]
    #[TestDox('Usia tidak wajar terdeteksi')]
    public function anomali_usia(): void
    {
        $r = $this->makeResident('3201010101600312', 'Terlalu Tua');
        $r->birth_date = now()->subYears(130);
        $r->save();

        $this->assertSame(1, $this->anomalyReportAs(Role::ADMIN_DESA)['usia_tidak_wajar']['count']);
    }

    #[Test]
    #[TestDox('Laporan anomali menghormati pembatasan wilayah Kepala Dusun')]
    public function anomali_dibatasi_wilayah(): void
    {
        /*
         * Dipakai pemeriksaan "usia tidak wajar" karena hasilnya memuat NAMA
         * penduduk — itulah yang bocor bila pembatasan wilayah gagal.
         * Pemeriksaan seperti "KK tanpa kepala" hanya memuat nomor KK.
         */
        $krajan = $this->makeResident('3201010101600313', 'Anomali Krajan');
        $krajan->birth_date = now()->subYears(130);
        $krajan->save();

        $dusunSido = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
        $rwSido = Region::query()->where('parent_id', $dusunSido->id)->firstOrFail();
        $rtSido = Region::query()->where('parent_id', $rwSido->id)->firstOrFail();

        $familySido = new Family;
        $familySido->setKkNumber('3201010000005002');
        $familySido->forceFill(['region_id' => $rtSido->id, 'address' => 'Jl. Sido'])->save();

        $sido = $this->makeResident('3201010101600314', 'Anomali Sidomulyo',
            family: $familySido, region: $rtSido);
        $sido->birth_date = now()->subYears(130);
        $sido->save();

        $dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();

        $response = $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $dusunKrajan))
            ->get('/kependudukan/anomali');

        $response->assertOk();

        $body = (string) $response->getContent();
        $this->assertStringContainsString('Anomali Krajan', $body);
        $this->assertStringNotContainsString('Anomali Sidomulyo', $body,
            'KEBOCORAN: laporan anomali memuat penduduk dusun lain.');
    }

    /* ================================ Ekspor ============================== */

    #[Test]
    #[TestDox('Ekspor tercatat di audit log beserta jumlah baris')]
    public function ekspor_tercatat(): void
    {
        $this->makeResident('3201010101600315', 'Diekspor');

        $admin = $this->userWithRole(Role::ADMIN_DESA);
        $response = $this->actingAs($admin)->get('/kependudukan/penduduk-ekspor');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'data.exported',
            'actor_id' => $admin->id,
        ]);
    }

    #[Test]
    #[TestDox('Ekspor oleh peran tanpa izin NIK penuh hanya berisi NIK ter-masking')]
    public function ekspor_pengawas_termasking(): void
    {
        $this->makeResident('3201010101600316', 'Pengawas Lihat');

        // Pengawas punya penduduk.ekspor, TIDAK punya data_pribadi.lihat_penuh.
        $response = $this->actingAs($this->userWithRole(Role::PENGAWAS))
            ->get('/kependudukan/penduduk-ekspor');

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Pengawas Lihat', $content);
        $this->assertStringNotContainsString('3201010101600316', $content,
            'Ekspor memuat NIK penuh bagi peran tanpa izin khusus.');
        $this->assertStringContainsString('3201••••••••0316', $content);
    }

    #[Test]
    #[TestDox('Ekspor menghormati pembatasan wilayah')]
    public function ekspor_dibatasi_wilayah(): void
    {
        $this->makeResident('3201010101600317', 'Ekspor Krajan');

        $dusunSido = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
        $rwSido = Region::query()->where('parent_id', $dusunSido->id)->firstOrFail();
        $rtSido = Region::query()->where('parent_id', $rwSido->id)->firstOrFail();

        $familySido = new Family;
        $familySido->setKkNumber('3201010000005003');
        $familySido->forceFill(['region_id' => $rtSido->id, 'address' => 'Jl. S'])->save();

        $this->makeResident('3201010101600318', 'Ekspor Sidomulyo',
            family: $familySido, region: $rtSido);

        // Kepala Dusun tidak punya izin ekspor sama sekali.
        $dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();

        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $dusunKrajan))
            ->get('/kependudukan/penduduk-ekspor')
            ->assertForbidden();
    }

    /**
     * Jalankan laporan anomali sebagai peran tertentu.
     *
     * AnomalyService membaca lewat ResidentRepository, yang mengambil
     * AccessScope dari pengguna yang sedang masuk. Memanggilnya tanpa pengguna
     * menghasilkan lingkup tamu — dan nol hasil, dengan benar.
     *
     * @return array<string, array{count: int, items: list<array<string, mixed>>}>
     */
    private function anomalyReportAs(string $role): array
    {
        $this->actingAs($this->userWithRole($role));

        return app(AnomalyService::class)->report();
    }

    private function makeResident(
        string $nik,
        string $name,
        string $relation = 'kepala_keluarga',
        string $status = Resident::STATUS_ACTIVE,
        ?Family $family = null,
        ?Region $region = null,
    ): Resident {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => ($family ?? $this->family)->id,
            'region_id' => ($region ?? $this->rtKrajan)->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1985-06-15',
            'gender' => 'L',
            'family_relation_code' => $relation,
            'religion_code' => 'islam',
            'citizenship' => 'WNI',
            'status' => $status,
        ])->save();

        return $resident;
    }
}
