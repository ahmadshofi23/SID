<?php

declare(strict_types=1);

namespace Tests\Feature\Population;

use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\ResidentMutation;
use App\Models\Role;
use App\Services\Population\StatisticsService;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Mutasi penduduk — FR-PDK-10, BR-03, RULE-03.
 */
final class MutationTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rt;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000006001');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Mutasi 1',
        ])->save();
    }

    #[Test]
    #[TestDox('Kematian mengubah status dan mengeluarkan penduduk dari statistik (RULE-03)')]
    public function kematian_mengubah_status_dan_statistik(): void
    {
        $resident = $this->makeResident('3201010101700201', 'Almarhum Uji');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        app(StatisticsService::class)->refresh();
        $sebelum = app(StatisticsService::class)->summary()['total'];

        $this->actingAs($admin)
            ->post("/kependudukan/penduduk/{$resident->id}/mutasi", [
                'type' => ResidentMutation::TYPE_DEATH,
                'mutation_date' => now()->subDays(3)->toDateString(),
                'death_cause' => 'Sakit',
                'death_place' => 'RSUD Contoh',
            ])->assertRedirect();

        $this->assertSame('meninggal', $resident->fresh()->status);

        $sesudah = app(StatisticsService::class)->summary()['total'];

        $this->assertSame($sebelum - 1, $sesudah,
            'Penduduk meninggal masih terhitung di statistik — melanggar RULE-03.');

        $this->assertDatabaseHas('resident_mutations', [
            'resident_id' => $resident->id,
            'type' => ResidentMutation::TYPE_DEATH,
        ]);
    }

    #[Test]
    #[TestDox('Kematian dua kali ditolak dengan pesan yang dapat dipahami')]
    public function kematian_ganda_ditolak(): void
    {
        $resident = $this->makeResident('3201010101700202', 'Sudah Meninggal');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $payload = [
            'type' => ResidentMutation::TYPE_DEATH,
            'mutation_date' => now()->subDay()->toDateString(),
        ];

        $this->actingAs($admin)->post("/kependudukan/penduduk/{$resident->id}/mutasi", $payload);

        $response = $this->actingAs($admin)
            ->post("/kependudukan/penduduk/{$resident->id}/mutasi", $payload);

        $response->assertSessionHasErrors('resident_id');
        $this->assertSame(1, ResidentMutation::query()
            ->where('resident_id', $resident->id)->count());
    }

    #[Test]
    #[TestDox('Tanggal kematian sebelum tanggal lahir ditolak')]
    public function tanggal_kematian_sebelum_lahir_ditolak(): void
    {
        $resident = $this->makeResident('3201010101700203', 'Mustahil');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/kependudukan/penduduk/{$resident->id}/mutasi", [
                'type' => ResidentMutation::TYPE_DEATH,
                'mutation_date' => '1970-01-01',
            ])->assertSessionHasErrors('mutation_date');
    }

    #[Test]
    #[TestDox('Tanggal mutasi di masa depan ditolak')]
    public function tanggal_masa_depan_ditolak(): void
    {
        $resident = $this->makeResident('3201010101700204', 'Masa Depan');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/kependudukan/penduduk/{$resident->id}/mutasi", [
                'type' => ResidentMutation::TYPE_DEATH,
                'mutation_date' => now()->addMonth()->toDateString(),
            ])->assertSessionHasErrors('mutation_date');
    }

    #[Test]
    #[TestDox('Pindah keluar lalu pindah datang mengembalikan status aktif')]
    public function pindah_keluar_lalu_datang(): void
    {
        $resident = $this->makeResident('3201010101700205', 'Perantau');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->post("/kependudukan/penduduk/{$resident->id}/mutasi", [
            'type' => ResidentMutation::TYPE_MOVE_OUT,
            'mutation_date' => now()->subMonths(6)->toDateString(),
            'destination_address' => 'Jakarta',
        ]);

        $this->assertSame('pindah_keluar', $resident->fresh()->status);

        $this->actingAs($admin)->post("/kependudukan/penduduk/{$resident->id}/mutasi", [
            'type' => ResidentMutation::TYPE_MOVE_IN,
            'mutation_date' => now()->subDay()->toDateString(),
            'origin_address' => 'Jakarta',
        ]);

        $this->assertSame(Resident::STATUS_ACTIVE, $resident->fresh()->status);
        $this->assertSame(2, ResidentMutation::query()
            ->where('resident_id', $resident->id)->count());
    }

    #[Test]
    #[TestDox('Kepala keluarga meninggal menghasilkan catatan audit, bukan penolakan')]
    public function kepala_keluarga_meninggal_dicatat(): void
    {
        $resident = $this->makeResident('3201010101700206', 'Kepala Uji');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/kependudukan/penduduk/{$resident->id}/mutasi", [
                'type' => ResidentMutation::TYPE_DEATH,
                'mutation_date' => now()->subDay()->toDateString(),
            ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'family.head_vacant',
            'entity_id' => $resident->id,
        ]);
    }

    #[Test]
    #[TestDox('Operator tidak dapat mencatat mutasi penduduk dusun lain')]
    public function kadus_tidak_dapat_mutasi_dusun_lain(): void
    {
        $resident = $this->makeResident('3201010101700207', 'Warga Krajan');
        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        // Kepala Dusun tidak punya izin mutasi.tambah sama sekali.
        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $dusunLain))
            ->post("/kependudukan/penduduk/{$resident->id}/mutasi", [
                'type' => ResidentMutation::TYPE_DEATH,
                'mutation_date' => now()->subDay()->toDateString(),
            ])->assertForbidden();

        $this->assertSame(Resident::STATUS_ACTIVE, $resident->fresh()->status);
    }

    #[Test]
    #[TestDox('Daftar mutasi menghormati pembatasan wilayah')]
    public function daftar_mutasi_dibatasi_wilayah(): void
    {
        $krajan = $this->makeResident('3201010101700208', 'Mutasi Krajan');

        $dusunSido = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
        $rwSido = Region::query()->where('parent_id', $dusunSido->id)->firstOrFail();
        $rtSido = Region::query()->where('parent_id', $rwSido->id)->firstOrFail();

        $familySido = new Family;
        $familySido->setKkNumber('3201010000006002');
        $familySido->forceFill(['region_id' => $rtSido->id, 'address' => 'Jl. Sido'])->save();

        $sido = $this->makeResident('3201010101700209', 'Mutasi Sidomulyo', $familySido, $rtSido);

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        foreach ([$krajan, $sido] as $r) {
            $this->actingAs($admin)->post("/kependudukan/penduduk/{$r->id}/mutasi", [
                'type' => ResidentMutation::TYPE_DEATH,
                'mutation_date' => now()->subDay()->toDateString(),
            ]);
        }

        $dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();

        $response = $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $dusunKrajan))
            ->get('/kependudukan/mutasi');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page): void {
            $names = collect($page->toArray()['props']['mutations']['data'])
                ->pluck('resident_name');

            $this->assertTrue($names->contains('Mutasi Krajan'));
            $this->assertFalse(
                $names->contains('Mutasi Sidomulyo'),
                'KEBOCORAN: daftar mutasi memuat penduduk dusun lain.'
            );
        });
    }

    private function makeResident(
        string $nik, string $name, ?Family $family = null, ?Region $region = null
    ): Resident {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => ($family ?? $this->family)->id,
            'region_id' => ($region ?? $this->rt)->id,
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
