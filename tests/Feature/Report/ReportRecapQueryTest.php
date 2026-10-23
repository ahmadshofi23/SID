<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Repositories\ResidentRepository;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Penjaga perbaikan kinerja rekap laporan — KEP-019, FR-LAP-03, NFR-PERF-01.
 *
 * Uji ini lahir dari uji beban Sprint 7 (docs/22-uji-beban-sprint-7.md §7).
 * Pada 20.000 penduduk, `ReportService::population()` terukur 755 ms —
 * melewati NFR-PERF-01 (p95 baca ≤ 500 ms) sendirian — karena ia membaca
 * seluruh tabel penduduk ENAM kali: sekali per kategori dan sekali lagi untuk
 * rekap per wilayah, yang bahkan menghidrasi 20.000 objek Eloquent.
 *
 * Yang dijaga di sini BUKAN kecepatannya. Angka milidetik berbeda di setiap
 * mesin dan akan menjadi uji yang goyah; yang dijaga adalah DUA SIFAT yang
 * membuatnya cepat dan yang akan hilang tanpa suara pada penulisan ulang
 * berikutnya:
 *
 *   1. jumlah KUERI-nya tetap satu per rekap, bukan satu per kategori;
 *   2. tidak ada baris penduduk yang dihidrasi untuk dihitung.
 *
 * Keduanya dapat diuji tanpa menyemai 20.000 baris, dan karena itu uji ini
 * tetap cepat.
 */
final class ReportRecapQueryTest extends TestCase
{
    use RefreshDatabase;

    private Region $rtKrajan;

    private Region $rtSidomulyo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->rtKrajan = $this->firstRtOf('Dusun Krajan');
        $this->rtSidomulyo = $this->firstRtOf('Dusun Sidomulyo');
    }

    #[Test]
    #[TestDox('Lima kategori direkap dengan SATU kueri, bukan lima')]
    public function rekap_lima_kategori_hanya_satu_kueri(): void
    {
        $this->seedResidents();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $recap = app(ResidentRepository::class)->recapByCategories([
            'gender', 'religion_code', 'education_code',
            'occupation_code', 'marital_status_code',
        ]);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(
            1,
            $queries,
            'Rekap lima kategori menghasilkan '.count($queries).' kueri. '
            .'Setiap kueri tambahan berarti satu kali lagi membaca seluruh '
            .'tabel penduduk — 70–88 ms per kali pada 20.000 baris (KEP-019).'
        );

        // Angkanya tetap benar: inilah yang membuat satu kueri cukup.
        $this->assertSame(['L' => 2, 'P' => 1], $recap['gender']);
        $this->assertSame(['islam' => 2, 'kristen' => 1], $recap['religion_code']);
        $this->assertSame(['slta' => 2, 'tamat_sd' => 1], $recap['education_code']);
    }

    #[Test]
    #[TestDox('Rekap per wilayah tidak menghidrasi satu pun baris penduduk')]
    public function rekap_per_wilayah_tidak_menghidrasi_penduduk(): void
    {
        $this->seedResidents();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $perWilayah = app(ResidentRepository::class)->recapByRegion();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        /*
         * Dua kueri: satu agregat penduduk, satu memuat tabel wilayah
         * (puluhan baris) untuk memetakan RT ke dusunnya. Kueri KETIGA berarti
         * pemetaan itu kembali menelusuri relasi per baris — bentuk N+1 yang
         * indeks `path` dibuat untuk menghindarinya.
         */
        $this->assertLessThanOrEqual(2, count($queries), implode("\n", array_merge(
            ['Rekap per wilayah menghasilkan kueri lebih banyak dari yang seharusnya:'],
            array_map(fn (array $q): string => '  '.$q['query'], $queries),
        )));

        $agregat = $queries[0]['query'];

        $this->assertStringContainsString(
            'count(*)',
            strtolower($agregat),
            'Rekap per wilayah wajib DIHITUNG basis data. Mengambil barisnya '
            .'lalu menghitung di PHP terukur 370 ms pada 20.000 penduduk, '
            .'dan 290 ms di antaranya hanya membangun objek yang dibuang.'
        );

        $krajan = collect($perWilayah)->firstWhere('region', 'Dusun Krajan');
        $sidomulyo = collect($perWilayah)->firstWhere('region', 'Dusun Sidomulyo');

        $this->assertSame(['region' => 'Dusun Krajan', 'total' => 2, 'laki' => 1, 'perempuan' => 1], $krajan);
        $this->assertSame(['region' => 'Dusun Sidomulyo', 'total' => 1, 'laki' => 1, 'perempuan' => 0], $sidomulyo);
    }

    #[Test]
    #[TestDox('Penduduk tanpa wilayah tetap ikut terhitung, dengan label sendiri')]
    public function penduduk_tanpa_wilayah_tetap_dihitung(): void
    {
        $this->seedResidents();
        $this->makeResident('3201010101900304', 'Tanpa Wilayah', null, 'L');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA));

        $perWilayah = app(ResidentRepository::class)->recapByRegion();

        /*
         * Laporan yang tidak dapat dijumlahkan sendiri adalah laporan yang
         * tidak dipercaya, lalu dikerjakan ulang dengan tangan. Karena itu
         * baris tanpa wilayah TIDAK boleh hilang begitu rekapnya dipindahkan
         * ke `GROUP BY` — dan `GROUP BY region_id` memang mengembalikan NULL
         * sebagai kelompoknya sendiri, bukan membuangnya.
         */
        $this->assertSame(
            4,
            collect($perWilayah)->sum('total'),
            'Jumlah seluruh baris wilayah harus sama dengan total penduduk.'
        );

        $this->assertNotNull(collect($perWilayah)->firstWhere('region', 'Tanpa wilayah'));
    }

    #[Test]
    #[TestDox('Kategori rekap di luar daftar putih ditolak (injeksi SQL)')]
    public function kategori_di_luar_daftar_putih_ditolak(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA));

        $this->expectException(InvalidArgumentException::class);

        /*
         * Nama kolom tidak dapat diikat sebagai parameter SQL — ia disisipkan
         * ke dalam teks kueri, dan nilainya berasal dari parameter HTTP.
         * Daftar putih adalah satu-satunya yang memisahkan laporan ini dari
         * injeksi SQL, dan pelebaran ke beberapa kolom sekaligus menambah
         * satu tempat baru yang dapat melewatkannya.
         */
        app(ResidentRepository::class)->recapByCategories(['nik_hash']);
    }

    #[Test]
    #[TestDox('Rekap satu kategori tetap bekerja seperti sebelumnya')]
    public function rekap_satu_kategori_tetap_bekerja(): void
    {
        $this->seedResidents();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA));

        $this->assertSame(
            ['L' => 2, 'P' => 1],
            app(ResidentRepository::class)->recapByCategory('gender')
        );
    }

    #[Test]
    #[TestDox('KEP-019: indeks idx_residents_status tetap menutup kolom rekap')]
    public function indeks_rekap_tidak_boleh_dipersempit_kembali(): void
    {
        /*
         * Uji skema, dan ia satu-satunya penjaga yang akan berbunyi bila
         * seseorang mempersempit indeks ini kembali menjadi dua kolom.
         * Kegagalannya di lapangan tidak berupa galat: halaman Laporan hanya
         * melambat menjadi tiga perempat detik, dan hanya pada basis data yang
         * sudah berisi puluhan ribu penduduk — yaitu di desa, bukan di CI.
         */
        $kolom = collect(DB::select('SHOW INDEX FROM residents WHERE Key_name = ?', ['idx_residents_status']))
            ->sortBy('Seq_in_index')
            ->pluck('Column_name')
            ->all();

        $this->assertSame([
            'status', 'deleted_at', 'region_id', 'gender',
            'religion_code', 'education_code', 'occupation_code', 'marital_status_code',
        ], $kolom, 'Lihat KEP-019 dan migrasi 2026_01_08_000100 untuk alasan tiap kolomnya.');
    }

    /* ========================= Pembantu ========================= */

    private function seedResidents(): void
    {
        $this->makeResident('3201010101900301', 'Satu Krajan', $this->rtKrajan, 'L', [
            'religion_code' => 'islam',
            'education_code' => 'slta',
            'occupation_code' => 'petani',
            'marital_status_code' => 'kawin_tercatat',
        ]);
        $this->makeResident('3201010101900302', 'Dua Krajan', $this->rtKrajan, 'P', [
            'religion_code' => 'islam',
            'education_code' => 'slta',
            'occupation_code' => 'guru',
            'marital_status_code' => 'kawin_tercatat',
        ]);
        $this->makeResident('3201010101900303', 'Satu Sidomulyo', $this->rtSidomulyo, 'L', [
            'religion_code' => 'kristen',
            'education_code' => 'tamat_sd',
            'occupation_code' => 'petani',
            'marital_status_code' => 'belum_kawin',
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeResident(
        string $nik,
        string $name,
        ?Region $region,
        string $gender,
        array $attributes = [],
    ): Resident {
        $family = new Family;
        $family->setKkNumber('32010100010'.substr($nik, -5));
        $family->forceFill([
            'region_id' => $region?->id,
            'address' => 'Jl. Uji No. 1',
        ])->save();

        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill(array_merge([
            'family_id' => $family->id,
            'region_id' => $region?->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1990-01-01',
            'gender' => $gender,
            'family_relation_code' => 'kepala_keluarga',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ], $attributes))->save();

        return $resident;
    }

    private function firstRtOf(string $dusun): Region
    {
        $dusunModel = Region::query()->where('name', $dusun)->firstOrFail();

        return Region::query()
            ->where('path', 'like', $dusunModel->path.'/%')
            ->where('level', 'rt')
            ->orderBy('path')
            ->firstOrFail();
    }
}
