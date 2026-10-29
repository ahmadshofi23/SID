<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Auth\AccessScope;
use App\Models\AuditLog;
use App\Models\Family;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Resident;
use App\Models\ResidentMutation;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Complaint\ComplaintService;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Laporan & ekspor — FR-LAP-03 s.d. FR-LAP-07, UC-18, BR-41.
 *
 * Yang dijaga kelas ini:
 *   1. SETIAP ekspor tercatat di audit log beserta JUMLAH BARIS (BR-41,
 *      SRS §4.9 butir 3) — termasuk buku administrasi, yang juga ekspor.
 *   2. Laporan mengikuti lingkup: Kepala Dusun merekap dusunnya sendiri,
 *      termasuk mutasi, yang tabelnya TIDAK dijaga tetapi menyimpan
 *      `resident_id`.
 *   3. NIK pada buku induk tersamar secara bawaan, dan pengungkapannya
 *      dicatat terpisah (RULE-07).
 *   4. `laporan.lihat` dan `laporan.ekspor` adalah dua gerbang berbeda.
 */
final class ReportTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rtKrajan;

    private Region $rtSidomulyo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->rtKrajan = $this->firstRtOf('Dusun Krajan');
        $this->rtSidomulyo = $this->firstRtOf('Dusun Sidomulyo');
    }

    /* ============================== Rekap ================================ */

    #[Test]
    #[TestDox('FR-LAP-03: rekap penduduk dikelompokkan per dusun dan per kategori')]
    public function rekap_penduduk(): void
    {
        $this->makeResident('3201010101900201', 'Satu Krajan', $this->rtKrajan, 'L');
        $this->makeResident('3201010101900202', 'Dua Krajan', $this->rtKrajan, 'P');
        $this->makeResident('3201010101900203', 'Satu Sidomulyo', $this->rtSidomulyo, 'L');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))->get('/laporan')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $penduduk = $page->toArray()['props']['penduduk'];

                $this->assertSame(3, $penduduk['total']);

                $perWilayah = collect($penduduk['per_wilayah'])->keyBy('region');

                $this->assertSame(2, $perWilayah['Dusun Krajan']['total']);
                $this->assertSame(1, $perWilayah['Dusun Krajan']['laki']);
                $this->assertSame(1, $perWilayah['Dusun Krajan']['perempuan']);
                $this->assertSame(1, $perWilayah['Dusun Sidomulyo']['total']);

                /*
                 * Jumlah seluruh baris wilayah HARUS sama dengan totalnya.
                 * Laporan yang tidak dapat dijumlahkan sendiri adalah laporan
                 * yang tidak dipercaya, lalu dikerjakan ulang dengan tangan.
                 */
                $this->assertSame(
                    $penduduk['total'],
                    collect($penduduk['per_wilayah'])->sum('total'),
                );
            });
    }

    #[Test]
    #[TestDox('Laporan mengikuti lingkup: Kepala Dusun merekap dusunnya sendiri')]
    public function rekap_mengikuti_lingkup(): void
    {
        $this->makeResident('3201010101900211', 'Satu Krajan', $this->rtKrajan, 'L');
        $this->makeResident('3201010101900212', 'Satu Sidomulyo', $this->rtSidomulyo, 'L');

        /*
         * Kepala Dusun tidak memegang `laporan.lihat` secara bawaan (matriks
         * RBAC docs/03 §4.9 memberinya "R(wilayah)" pada baris laporan).
         * Diberikan di sini supaya yang diuji benar-benar LINGKUP dan bukan
         * izin — tanpa itu, 403 akan menutupi pertanyaannya.
         *
         * Diberikan SEBELUM penggunanya dibuat: izin efektif dimuat bersama
         * pengguna, jadi pemberian setelahnya tidak terbaca.
         */
        $this->grant(Role::KEPALA_DUSUN, 'laporan.lihat');

        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->regionNamed('Dusun Krajan'));

        $this->actingAs($kadus)->get('/laporan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('penduduk.total', 1)
                // Layar WAJIB mengatakan lingkupnya; lihat ReportController.
                ->where('lingkup', 'Wilayah tugas Anda'));
    }

    #[Test]
    #[TestDox('FR-LAP-04: rekap mutasi mengikuti lingkup walau tabelnya tidak dijaga')]
    public function rekap_mutasi_mengikuti_lingkup(): void
    {
        $krajan = $this->makeResident('3201010101900221', 'Warga Krajan', $this->rtKrajan, 'L');
        $sidomulyo = $this->makeResident('3201010101900222', 'Warga Sidomulyo', $this->rtSidomulyo, 'L');

        $this->makeMutation($krajan, ResidentMutation::TYPE_MOVE_IN);
        $this->makeMutation($sidomulyo, ResidentMutation::TYPE_DEATH);

        $this->grant(Role::KEPALA_DUSUN, 'laporan.lihat');

        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->regionNamed('Dusun Krajan'));

        /*
         * `resident_mutations` BUKAN tabel dijaga, sehingga tidak ada apa pun
         * di basis data yang mencegah Kepala Dusun melihat seluruh mutasi
         * desa — termasuk siapa saja yang MENINGGAL di dusun lain. Yang
         * menjaganya adalah subkueri ResidentRepository::visibleIdsQuery().
         */
        $this->actingAs($kadus)->get('/laporan')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $mutasi = $page->toArray()['props']['mutasi'];

                $this->assertSame(1, $mutasi['total']);

                $perJenis = collect($mutasi['per_jenis'])->keyBy('kode');

                $this->assertSame(1, $perJenis['pindah_datang']['jumlah']);
                $this->assertSame(
                    0,
                    $perJenis['mati']['jumlah'],
                    'KEBOCORAN: kematian di dusun lain terhitung di rekap Kepala Dusun.'
                );

                // Seluruh jenis tetap muncul walau nol: "tidak ada kematian"
                // adalah keterangan yang berbeda dari "tidak dilaporkan".
                $this->assertCount(5, $mutasi['per_jenis']);
            });
    }

    /* ============================== Izin ================================= */

    #[Test]
    #[TestDox('laporan.lihat dan laporan.ekspor adalah dua gerbang berbeda')]
    public function melihat_dan_mengekspor_dipisah(): void
    {
        /*
         * Kasi Pelayanan memegang `laporan.lihat` TANPA `laporan.ekspor`
         * (RoleSeeder). Ia boleh membaca angkanya di layar tetapi tidak
         * memindahkannya keluar sistem — dan layarnya menyembunyikan tombol
         * unduh, bukan menampilkannya lalu menolak.
         */
        $kasi = $this->userWithRole(Role::KASI_PELAYANAN);

        $this->assertTrue($kasi->hasPermission('laporan.lihat'));
        $this->assertFalse($kasi->hasPermission('laporan.ekspor'));

        $this->actingAs($kasi)->get('/laporan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('izin.ekspor', false));

        $this->actingAs($kasi)->get('/laporan/penduduk.csv')->assertForbidden();
    }

    #[Test]
    #[TestDox('Warga tidak dapat membuka laporan sama sekali')]
    public function warga_tidak_dapat_membuka_laporan(): void
    {
        $warga = $this->userWithRole(Role::WARGA);

        $this->actingAs($warga)->get('/laporan')->assertForbidden();
        $this->actingAs($warga)->get('/laporan/penduduk.csv')->assertForbidden();
        $this->actingAs($warga)->get('/laporan/buku-induk.pdf')->assertForbidden();
    }

    /* ============================= Ekspor ================================ */

    #[Test]
    #[TestDox('BR-41: setiap ekspor tercatat di audit log beserta jumlah barisnya')]
    public function setiap_ekspor_tercatat(): void
    {
        $this->makeResident('3201010101900231', 'Satu Krajan', $this->rtKrajan, 'L');
        $this->makeResident('3201010101900232', 'Dua Krajan', $this->rtKrajan, 'P');

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        foreach ([
            '/laporan/penduduk.csv' => 'laporan_penduduk',
            '/laporan/mutasi.csv' => 'laporan_mutasi',
            '/laporan/layanan.csv' => 'laporan_layanan',
            '/laporan/pengaduan.csv' => 'laporan_pengaduan',
        ] as $url => $entity) {
            $response = $this->actingAs($admin)->get($url);

            $response->assertOk();
            $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

            $log = AuditLog::query()
                ->where('action', AuditLogger::ACTION_EXPORTED)
                ->get()
                ->first(fn (AuditLog $l): bool => ($l->context['entity_type'] ?? null) === $entity);

            $this->assertNotNull($log, "Ekspor {$entity} tidak tercatat di audit log.");
            $this->assertArrayHasKey(
                'rows',
                (array) $log->context,
                "Ekspor {$entity} tercatat tanpa jumlah baris (BR-41)."
            );
        }
    }

    #[Test]
    #[TestDox('CSV diawali BOM UTF-8 supaya Excel di Windows membaca huruf beraksen dengan benar')]
    public function csv_berawalan_bom(): void
    {
        $this->makeResident('3201010101900241', "Ma'rufah Krajan", $this->rtKrajan, 'P');

        $isi = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get('/laporan/penduduk.csv')
            ->assertOk()
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi);
        $this->assertStringContainsString('Dusun Krajan', $isi);
    }

    /* ======================== Buku administrasi ========================== */

    #[Test]
    #[TestDox('FR-LAP-06: buku induk tercetak, NIK tersamar secara bawaan, dan ekspornya tercatat')]
    public function buku_induk_nik_tersamar(): void
    {
        $penduduk = $this->makeResident('3201010101900251', 'Warga Krajan', $this->rtKrajan, 'L');

        /*
         * Operator memegang `penduduk.ekspor`? Tidak — maka dipakai Admin
         * Desa, yang memang memegang keduanya. Yang diuji di sini adalah
         * NIK-nya, bukan izinnya.
         */
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->assertTrue($admin->hasPermission('data_pribadi.lihat_penuh'));

        $response = $this->actingAs($admin)->get('/laporan/buku-induk.pdf')->assertOk();

        $response->assertHeader('content-type', 'application/pdf');

        $log = AuditLog::query()->get()
            ->first(fn (AuditLog $l): bool => ($l->context['entity_type'] ?? null) === 'buku_induk_penduduk');

        $this->assertNotNull($log, 'Pencetakan buku induk tidak tercatat sebagai ekspor (BR-41).');
        $this->assertSame(1, $log->context['rows'] ?? null);
        $this->assertTrue($log->context['filters']['nik_penuh'] ?? false);

        /*
         * Pengungkapan NIK dicatat TERPISAH dari ekspornya (RULE-07): "siapa
         * mencetak buku induk" dan "siapa melihat NIK lengkap warga" adalah
         * dua pertanyaan audit yang berbeda, dan yang kedua adalah yang
         * benar-benar sensitif.
         */
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'data.pii_revealed')->count(),
            'Pencetakan buku induk ber-NIK penuh tidak dicatat sebagai pengungkapan NIK.'
        );

        // Tanpa izin NIK penuh: tersamar, dan tanpa catatan pengungkapan.
        AuditLog::query()->delete();
        $this->revoke(Role::KASI_PEMERINTAHAN, 'data_pribadi.lihat_penuh');
        $this->grant(Role::KASI_PEMERINTAHAN, 'penduduk.ekspor');

        $kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);

        $this->actingAs($kasi)->get('/laporan/buku-induk.pdf')->assertOk();

        $log = AuditLog::query()->get()
            ->first(fn (AuditLog $l): bool => ($l->context['entity_type'] ?? null) === 'buku_induk_penduduk');

        $this->assertFalse($log?->context['filters']['nik_penuh'] ?? true);
        $this->assertSame(0, AuditLog::query()->where('action', 'data.pii_revealed')->count());
        $this->assertNotNull($penduduk->nik_masked);
    }

    #[Test]
    #[TestDox('Buku administrasi menuntut penduduk.ekspor, bukan hanya laporan.lihat')]
    public function buku_menuntut_izin_ekspor_penduduk(): void
    {
        /*
         * Yang keluar dari buku induk bukan rekap melainkan satu baris per
         * warga beserta nama, tanggal lahir, dan alamatnya — ekspor data
         * penduduk dalam bentuk lain, jadi izinnya harus izin yang sama.
         */
        $kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);

        $this->assertTrue($kasi->hasPermission('laporan.lihat'));
        $this->assertFalse($kasi->hasPermission('penduduk.ekspor'));

        $this->actingAs($kasi)->get('/laporan')->assertOk();
        $this->actingAs($kasi)->get('/laporan/buku-induk.pdf')->assertForbidden();
        $this->actingAs($kasi)->get('/laporan/buku-mutasi.pdf')->assertForbidden();
    }

    #[Test]
    #[TestDox('Buku mutasi tercetak untuk periode yang diminta dan ekspornya tercatat')]
    public function buku_mutasi(): void
    {
        $penduduk = $this->makeResident('3201010101900261', 'Warga Krajan', $this->rtKrajan, 'L');
        $this->makeMutation($penduduk, ResidentMutation::TYPE_MOVE_IN);

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->get('/laporan/buku-mutasi.pdf?dari='.now()->subYear()->toDateString()
                .'&sampai='.now()->toDateString())
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $log = AuditLog::query()->get()
            ->first(fn (AuditLog $l): bool => ($l->context['entity_type'] ?? null) === 'buku_mutasi_penduduk');

        $this->assertNotNull($log);
        $this->assertSame(1, $log->context['rows'] ?? null);
    }

    #[Test]
    #[TestDox('Tanggal yang tidak dikenali kembali ke periode bawaan, bukan menggagalkan laporan')]
    public function tanggal_salah_tidak_menggagalkan(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        /*
         * Laporan yang menolak terbuka karena satu parameter URL salah ketik
         * hanya membuat petugas menyimpulkan menunya rusak — dan menelepon
         * admin alih-alih memperbaiki tanggalnya.
         */
        $this->actingAs($admin)->get('/laporan?dari=kemarin&sampai=32-13-2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('mutasi.dari'));
    }

    /* ============================== Pengaduan ============================= */

    #[Test]
    #[TestDox('Rekap pengaduan null bagi peran tanpa izin pengaduan, bukan nol')]
    public function rekap_pengaduan_null_tanpa_izin(): void
    {
        app(ComplaintService::class)->create(
            AccessScope::forUser($this->userWithRole(Role::WARGA)),
            [
                'category_code' => 'infrastruktur',
                'title' => 'Lampu jalan mati di RT 002',
                'body' => 'Sudah dua bulan lampu jalan di depan pos ronda mati.',
            ],
        );

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))->get('/laporan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pengaduan.total', 1)
                ->where('pengaduan.terbuka', 1));

        /*
         * Kaur Keuangan memegang `laporan.lihat` tetapi TIDAK
         * `pengaduan.lihat`. Rekapnya harus null — bukan "0 pengaduan", yang
         * merupakan pernyataan yang salah dan di laporan pimpinan berakhir
         * sebagai keputusan.
         */
        $kaur = $this->userWithRole(Role::KAUR_KEUANGAN);

        $this->assertFalse($kaur->hasPermission('pengaduan.lihat'));

        $this->actingAs($kaur)->get('/laporan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pengaduan', null));
    }

    /* ============================== Pembantu ============================== */

    private function grant(string $role, string $permission): void
    {
        $p = Permission::query()->where('code', $permission)->firstOrFail();

        Role::query()->where('code', $role)->firstOrFail()
            ->permissions()->syncWithoutDetaching([$p->id]);
    }

    private function revoke(string $role, string $permission): void
    {
        $p = Permission::query()->where('code', $permission)->firstOrFail();

        Role::query()->where('code', $role)->firstOrFail()
            ->permissions()->detach($p->id);
    }

    private function regionNamed(string $name): Region
    {
        return Region::query()->where('name', $name)->firstOrFail();
    }

    private function firstRtOf(string $dusun): Region
    {
        $rw = Region::query()->where('parent_id', $this->regionNamed($dusun)->id)->firstOrFail();

        return Region::query()->where('parent_id', $rw->id)->firstOrFail();
    }

    private function makeResident(string $nik, string $name, Region $region, string $gender): Resident
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
            'birth_place' => 'Sukamaju',
            'birth_date' => '1990-01-01',
            'gender' => $gender,
            'family_relation_code' => 'kepala_keluarga',
            'religion_code' => 'islam',
            'marital_status_code' => 'kawin_tercatat',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }

    private function makeMutation(Resident $resident, string $type): ResidentMutation
    {
        $mutation = new ResidentMutation;
        $mutation->forceFill([
            'resident_id' => $resident->id,
            'type' => $type,
            'mutation_date' => now()->toDateString(),
            'reason' => 'Uji',
            // Kolomnya NOT NULL tanpa bawaan: mutasi selalu punya pencatat.
            'created_by' => $this->userWithRole(Role::OPERATOR)->id,
        ])->save();

        return $mutation;
    }
}
