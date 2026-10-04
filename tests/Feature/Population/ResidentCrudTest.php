<?php

declare(strict_types=1);

namespace Tests\Feature\Population;

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Modul kependudukan — FR-PDK-01, 02, 06, 07 & aturan bisnis RULE-01/02/08.
 */
final class ResidentCrudTest extends TestCase
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
        $this->family->setKkNumber('3201010000009001');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Uji No. 1',
        ])->save();
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nik' => '3201011503900001',
            'full_name' => 'Ahmad Uji',
            'birth_place' => 'Sukamaju',
            'birth_date' => '1990-03-15',
            'gender' => 'L',
            'family_id' => $this->family->id,
            'family_relation_code' => 'kepala_keluarga',
            'region_id' => $this->rt->id,
            'religion_code' => 'islam',
            'marital_status_code' => 'belum_kawin',
            'education_code' => 'slta',
            'occupation_code' => 'petani',
        ], $overrides);
    }

    #[Test]
    #[TestDox('Operator dapat menambah penduduk dan NIK tersimpan terenkripsi')]
    public function operator_dapat_menambah_penduduk(): void
    {
        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post('/kependudukan/penduduk', $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('residents', ['full_name' => 'Ahmad Uji']);

        $raw = (string) DB::table('residents')
            ->where('full_name', 'Ahmad Uji')->value('nik_enc');

        $this->assertStringNotContainsString('3201011503900001', $raw,
            'NIK tersimpan sebagai teks terang — enkripsi tidak bekerja.');

        $this->assertDatabaseHas('residents', ['nik_masked' => '3201••••••••0001']);
    }

    #[Test]
    #[TestDox('RULE-01: NIK ganda ditolak dengan pesan yang dapat dipahami')]
    public function nik_ganda_ditolak(): void
    {
        $user = $this->userWithRole(Role::OPERATOR);

        $this->actingAs($user)->post('/kependudukan/penduduk', $this->validPayload());

        $response = $this->actingAs($user)->post('/kependudukan/penduduk', $this->validPayload([
            'full_name' => 'Orang Lain',
            'family_relation_code' => 'anak',
        ]));

        $response->assertSessionHasErrors('nik');
        $this->assertDatabaseMissing('residents', ['full_name' => 'Orang Lain']);
    }

    #[Test]
    #[TestDox('Pesan NIK ganda TIDAK menyebut nama pemilik NIK (anti kebocoran)')]
    public function pesan_nik_ganda_tidak_membocorkan_identitas(): void
    {
        $user = $this->userWithRole(Role::ADMIN_DESA);
        $this->actingAs($user)->post('/kependudukan/penduduk', $this->validPayload([
            'full_name' => 'Rahasia Sekali',
        ]));

        $response = $this->actingAs($user)->post('/kependudukan/penduduk', $this->validPayload([
            'full_name' => 'Pendatang', 'family_relation_code' => 'anak',
        ]));

        $message = $this->errorMessage($response, 'nik');

        $this->assertStringNotContainsString('Rahasia Sekali', $message,
            'Pesan galat membocorkan identitas pemilik NIK — Kepala Dusun bisa '
            .'memakainya untuk mengintip warga dusun lain.');
    }

    #[Test]
    #[TestDox('RULE-02: kepala keluarga kedua ditolak dengan menyebut siapa yang sudah ada')]
    public function kepala_keluarga_kedua_ditolak(): void
    {
        $user = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($user)->post('/kependudukan/penduduk', $this->validPayload([
            'full_name' => 'Kepala Pertama',
        ]));

        $response = $this->actingAs($user)->post('/kependudukan/penduduk', $this->validPayload([
            'nik' => '3201011503900002', 'full_name' => 'Kepala Kedua',
        ]));

        $response->assertSessionHasErrors('family_relation_code');

        // Di sini menyebut nama JUSTRU membantu: keduanya dalam satu KK yang
        // sama, jadi petugas memang berhak melihat keduanya.
        $this->assertStringContainsString(
            'Kepala Pertama',
            $this->errorMessage($response, 'family_relation_code')
        );
    }

    #[Test]
    #[TestDox('Tanggal lahir di masa depan ditolak (pengganti CHECK constraint)')]
    public function tanggal_lahir_masa_depan_ditolak(): void
    {
        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post('/kependudukan/penduduk', $this->validPayload([
                'birth_date' => now()->addYear()->toDateString(),
            ]))
            ->assertSessionHasErrors('birth_date');
    }

    #[Test]
    #[TestDox('NIK bukan 16 digit ditolak dengan menyebut jumlah angka')]
    public function nik_format_salah_ditolak(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post('/kependudukan/penduduk', $this->validPayload(['nik' => '32010115039']));

        $response->assertSessionHasErrors('nik');
        $this->assertStringContainsString('11 angka', $this->errorMessage($response, 'nik'));
    }

    #[Test]
    #[TestDox('Kode referensi yang tidak dikenal ditolak')]
    public function kode_referensi_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post('/kependudukan/penduduk', $this->validPayload([
                'religion_code' => 'bukan_agama',
            ]))
            ->assertSessionHasErrors('religion_code');
    }

    #[Test]
    #[TestDox('Perubahan data tercatat per-field di riwayat (FR-PDK-07)')]
    public function perubahan_tercatat_per_field(): void
    {
        $user = $this->userWithRole(Role::ADMIN_DESA);
        $this->actingAs($user)->post('/kependudukan/penduduk', $this->validPayload());

        $resident = Resident::query()->where('full_name', 'Ahmad Uji')->firstOrFail();

        $this->actingAs($user)->put("/kependudukan/penduduk/{$resident->id}", $this->validPayload([
            'nik' => '',                      // kosong = tidak diubah
            'full_name' => 'Ahmad Uji Revisi',
            'occupation_code' => 'guru',
        ]));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'resident.updated',
            'entity_id' => $resident->id,
        ]);

        $entry = AuditLog::query()
            ->where('action', 'resident.updated')->latest('id')->firstOrFail();

        $this->assertSame('Ahmad Uji', $entry->changes['full_name']['old']);
        $this->assertSame('Ahmad Uji Revisi', $entry->changes['full_name']['new']);
        $this->assertSame('guru', $entry->changes['occupation_code']['new']);
    }

    #[Test]
    #[TestDox('Mengubah data tanpa mengisi NIK TIDAK menghapus NIK lama')]
    public function nik_kosong_saat_ubah_tidak_menghapus_nik(): void
    {
        $user = $this->userWithRole(Role::ADMIN_DESA);
        $this->actingAs($user)->post('/kependudukan/penduduk', $this->validPayload());

        $resident = Resident::query()->where('full_name', 'Ahmad Uji')->firstOrFail();
        $hashBefore = $resident->nik_hash;

        $this->actingAs($user)->put("/kependudukan/penduduk/{$resident->id}",
            $this->validPayload(['nik' => '', 'full_name' => 'Nama Baru']));

        $resident->refresh();

        $this->assertSame($hashBefore, $resident->nik_hash);
        $this->assertSame('3201011503900001', $resident->revealNik());
    }

    #[Test]
    #[TestDox('RULE-08: penghapusan hanya soft delete dan wajib beralasan')]
    public function penghapusan_hanya_soft_delete(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);
        $this->actingAs($admin)->post('/kependudukan/penduduk', $this->validPayload());
        $resident = Resident::query()->where('full_name', 'Ahmad Uji')->firstOrFail();

        // Tanpa alasan -> ditolak
        $this->actingAs($admin)
            ->delete("/kependudukan/penduduk/{$resident->id}", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)->delete("/kependudukan/penduduk/{$resident->id}", [
            'reason' => 'Data ganda hasil impor',
        ])->assertRedirect();

        // Baris MASIH ADA, hanya ditandai terhapus.
        $this->assertDatabaseHas('residents', ['id' => $resident->id]);
        $this->assertNotNull($resident->fresh()->deleted_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'resident.deleted', 'entity_id' => $resident->id,
        ]);
    }

    #[Test]
    #[TestDox('RULE-01 dilonggarkan untuk penduduk terhapus: NIK dapat dipakai ulang')]
    public function nik_penduduk_terhapus_dapat_dipakai_ulang(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);
        $this->actingAs($admin)->post('/kependudukan/penduduk', $this->validPayload());
        $resident = Resident::query()->where('full_name', 'Ahmad Uji')->firstOrFail();

        $this->actingAs($admin)->delete("/kependudukan/penduduk/{$resident->id}", [
            'reason' => 'Salah input, akan dimasukkan ulang',
        ]);

        $this->actingAs($admin)
            ->post('/kependudukan/penduduk', $this->validPayload(['full_name' => 'Ahmad Benar']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('residents', ['full_name' => 'Ahmad Benar']);
    }

    #[Test]
    #[TestDox('Operator TIDAK dapat menghapus penduduk (matriks RBAC)')]
    public function operator_tidak_dapat_menghapus(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);
        $this->actingAs($admin)->post('/kependudukan/penduduk', $this->validPayload());
        $resident = Resident::query()->where('full_name', 'Ahmad Uji')->firstOrFail();

        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->delete("/kependudukan/penduduk/{$resident->id}", ['reason' => 'coba hapus'])
            ->assertForbidden();

        $this->assertNull($resident->fresh()->deleted_at);
    }

    #[Test]
    #[TestDox('Pengawas tidak dapat menambah maupun mengubah data')]
    public function pengawas_tidak_dapat_menulis(): void
    {
        $pengawas = $this->userWithRole(Role::PENGAWAS);

        $this->actingAs($pengawas)->get('/kependudukan/penduduk')->assertOk();
        $this->actingAs($pengawas)->get('/kependudukan/penduduk/tambah')->assertForbidden();
        $this->actingAs($pengawas)
            ->post('/kependudukan/penduduk', $this->validPayload())->assertForbidden();
    }
}
