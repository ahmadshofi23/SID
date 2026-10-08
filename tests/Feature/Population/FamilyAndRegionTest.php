<?php

declare(strict_types=1);

namespace Tests\Feature\Population;

use App\Models\AuditLog;
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

final class FamilyAndRegionTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();
    }

    /* ============================ Kartu Keluarga ========================== */

    #[Test]
    #[TestDox('Operator dapat membuat kartu keluarga; nomor KK tersimpan terenkripsi')]
    public function membuat_kartu_keluarga(): void
    {
        $this->actingAs($this->userWithRole(Role::OPERATOR))->post('/kependudukan/keluarga', [
            'kk_number' => '3201010000007001',
            'region_id' => $this->rt->id,
            'address' => 'Jl. Anggrek No. 5',
        ])->assertRedirect();

        $this->assertDatabaseHas('families', ['kk_number_masked' => '3201••••••••7001']);

        $raw = (string) DB::table('families')
            ->where('address', 'Jl. Anggrek No. 5')->value('kk_number_enc');

        $this->assertStringNotContainsString('3201010000007001', $raw);
    }

    #[Test]
    #[TestDox('Nomor KK ganda ditolak')]
    public function nomor_kk_ganda_ditolak(): void
    {
        $user = $this->userWithRole(Role::OPERATOR);
        $payload = [
            'kk_number' => '3201010000007002',
            'region_id' => $this->rt->id,
            'address' => 'Jl. Satu',
        ];

        $this->actingAs($user)->post('/kependudukan/keluarga', $payload);

        $this->actingAs($user)
            ->post('/kependudukan/keluarga', array_merge($payload, ['address' => 'Jl. Dua']))
            ->assertSessionHasErrors('kk_number');

        $this->assertDatabaseMissing('families', ['address' => 'Jl. Dua']);
    }

    #[Test]
    #[TestDox('KK yang masih punya anggota tidak dapat dihapus')]
    public function kk_berisi_anggota_tidak_dapat_dihapus(): void
    {
        $family = $this->makeFamily('3201010000007003');
        $this->makeResident($family, '3201010101700101', 'Penghuni');

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->delete("/kependudukan/keluarga/{$family->id}", ['reason' => 'tidak terpakai']);

        $response->assertSessionHasErrors('family');
        $this->assertNull($family->fresh()->deleted_at);
        $this->assertStringContainsString(
            '1 anggota', $this->errorMessage($response, 'family')
        );
    }

    #[Test]
    #[TestDox('Pemindahan anggota antar KK tercatat beserta alasannya (FR-PDK-09)')]
    public function pemindahan_anggota_tercatat(): void
    {
        $asal = $this->makeFamily('3201010000007004');
        $tujuan = $this->makeFamily('3201010000007005');
        $resident = $this->makeResident($asal, '3201010101700102', 'Pindahan');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/kependudukan/keluarga/{$asal->id}/pindah-anggota", [
                'resident_id' => $resident->id,
                'target_family_id' => $tujuan->id,
                'family_relation_code' => 'anak',
                'reason' => 'Menikah dan membentuk keluarga baru',
            ])->assertRedirect();

        $resident->refresh();
        $this->assertSame($tujuan->id, $resident->family_id);
        $this->assertSame('anak', $resident->family_relation_code);

        $entry = AuditLog::query()
            ->where('action', 'family.member_moved')->latest('id')->firstOrFail();

        $this->assertSame($asal->id, $entry->changes['family_id']['old']);
        $this->assertSame($tujuan->id, $entry->changes['family_id']['new']);
        $this->assertSame('Menikah dan membentuk keluarga baru', $entry->context['alasan']);
    }

    #[Test]
    #[TestDox('Pemindahan tanpa alasan ditolak')]
    public function pemindahan_tanpa_alasan_ditolak(): void
    {
        $asal = $this->makeFamily('3201010000007006');
        $tujuan = $this->makeFamily('3201010000007007');
        $resident = $this->makeResident($asal, '3201010101700103', 'Tanpa Alasan');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/kependudukan/keluarga/{$asal->id}/pindah-anggota", [
                'resident_id' => $resident->id,
                'target_family_id' => $tujuan->id,
                'family_relation_code' => 'anak',
                'reason' => '',
            ])->assertSessionHasErrors('reason');

        $this->assertSame($asal->id, $resident->fresh()->family_id);
    }

    #[Test]
    #[TestDox('Pindah menjadi kepala keluarga ditolak bila tujuan sudah punya kepala')]
    public function pindah_jadi_kepala_kedua_ditolak(): void
    {
        $asal = $this->makeFamily('3201010000007008');
        $tujuan = $this->makeFamily('3201010000007009');
        $pindahan = $this->makeResident($asal, '3201010101700104', 'Calon Kepala');
        $this->makeResident($tujuan, '3201010101700105', 'Kepala Lama');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/kependudukan/keluarga/{$asal->id}/pindah-anggota", [
                'resident_id' => $pindahan->id,
                'target_family_id' => $tujuan->id,
                'family_relation_code' => 'kepala_keluarga',
                'reason' => 'Mengambil alih kepala keluarga',
            ])->assertSessionHasErrors('family_relation_code');
    }

    /* ================================ Wilayah ============================= */

    #[Test]
    #[TestDox('Admin Desa dapat menambah dusun; path terbentuk otomatis')]
    public function menambah_dusun(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))->post('/kependudukan/wilayah', [
            'level' => 'dusun',
            'code' => '009',
            'name' => 'Dusun Baru',
        ])->assertRedirect();

        $region = Region::query()->where('name', 'Dusun Baru')->firstOrFail();
        $this->assertSame('dusun-009', $region->path);
    }

    #[Test]
    #[TestDox('RT tanpa induk RW ditolak (hierarki dijaga)')]
    public function hierarki_wilayah_dijaga(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))->post('/kependudukan/wilayah', [
            'level' => 'rt',
            'code' => '001',
            'name' => 'RT Liar',
        ])->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('regions', ['name' => 'RT Liar']);
    }

    #[Test]
    #[TestDox('RW dengan induk bertingkat salah ditolak')]
    public function induk_bertingkat_salah_ditolak(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))->post('/kependudukan/wilayah', [
            'level' => 'rw',
            'parent_id' => $this->rt->id,     // RT dipakai sebagai induk RW
            'code' => '009',
            'name' => 'RW Salah',
        ])->assertSessionHasErrors('parent_id');
    }

    #[Test]
    #[TestDox('Wilayah yang masih dihuni tidak dapat dihapus')]
    public function wilayah_berpenghuni_tidak_dapat_dihapus(): void
    {
        $family = $this->makeFamily('3201010000007010');
        $this->makeResident($family, '3201010101700106', 'Penghuni RT');

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->delete("/kependudukan/wilayah/{$this->rt->id}");

        $response->assertSessionHasErrors('region');
        $this->assertDatabaseHas('regions', ['id' => $this->rt->id]);
    }

    #[Test]
    #[TestDox('Dusun yang masih punya RW tidak dapat dihapus')]
    public function dusun_dengan_turunan_tidak_dapat_dihapus(): void
    {
        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->delete("/kependudukan/wilayah/{$dusun->id}")
            ->assertSessionHasErrors('region');
    }

    #[Test]
    #[TestDox('Mengubah kode wilayah menyinkronkan path seluruh turunannya')]
    public function perubahan_kode_menyinkronkan_path_turunan(): void
    {
        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->put("/kependudukan/wilayah/{$dusun->id}", [
                'level' => 'dusun',
                'code' => '099',
                'name' => 'Dusun Krajan',
            ])->assertRedirect();

        $this->rt->refresh();

        $this->assertStringStartsWith('dusun-099/', $this->rt->path,
            'Path turunan tidak ikut disinkronkan — Kepala Dusun akan kehilangan '
            .'akses ke wilayahnya sendiri, atau memperoleh akses ke wilayah lain.');
    }

    #[Test]
    #[TestDox('REGRESI: halaman Wilayah merender pohon 3 tingkat tanpa lazy loading')]
    public function halaman_wilayah_merender_pohon_penuh(): void
    {
        /*
         * REGRESI 2026-10-08 — HTTP 500 di halaman Wilayah.
         *
         * `tree()` memuat dua tingkat (with 'children.children') sementara
         * penyaji menelusuri tiga, sehingga akses ke children milik RT
         * melanggar larangan lazy loading.
         *
         * Uji ini lolos sebelumnya HANYA karena TestCase mematikan larangan
         * tersebut. Larangan kini aktif, jadi uji inilah yang menjaga bahwa
         * seluruh kedalaman pohon benar-benar termuat.
         */
        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get('/kependudukan/wilayah');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page): void {
            $tree = $page->toArray()['props']['tree'];

            $this->assertCount(2, $tree, 'Harus ada 2 dusun.');

            $dusun = $tree[0];
            $this->assertNotEmpty($dusun['children'], 'Dusun harus memuat RW.');

            $rw = $dusun['children'][0];
            $this->assertNotEmpty($rw['children'], 'RW harus memuat RT.');

            // Tingkat terdalam: di sinilah bug aslinya meledak.
            $rt = $rw['children'][0];
            $this->assertSame('rt', $rt['level']);
            $this->assertSame([], $rt['children'], 'RT tidak punya turunan.');
        });
    }

    #[Test]
    #[TestDox('Operator tidak dapat mengelola wilayah')]
    public function operator_tidak_dapat_mengelola_wilayah(): void
    {
        $operator = $this->userWithRole(Role::OPERATOR);

        $this->actingAs($operator)->get('/kependudukan/wilayah')->assertOk();
        $this->actingAs($operator)->get('/kependudukan/wilayah/tambah')->assertForbidden();
        $this->actingAs($operator)->post('/kependudukan/wilayah', [
            'level' => 'dusun', 'code' => '010', 'name' => 'Coba',
        ])->assertForbidden();
    }

    private function makeFamily(string $kkNumber): Family
    {
        $family = new Family;
        $family->setKkNumber($kkNumber);
        $family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Alamat '.$kkNumber,
        ])->save();

        return $family;
    }

    private function makeResident(Family $family, string $nik, string $name): Resident
    {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $this->rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1985-01-01',
            'gender' => 'L',
            'family_relation_code' => 'kepala_keluarga',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }
}
