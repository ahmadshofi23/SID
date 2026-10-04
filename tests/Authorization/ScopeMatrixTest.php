<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Auth\AccessScope;
use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * TC-SEC-SCOPE-01 — Pembatasan wilayah & keluarga (docs/09-testing-qa.md §5).
 *
 * KRITIS. Tanpa Row-Level Security (KEP-002), uji ini adalah SATU-SATUNYA
 * verifikasi otomatis bahwa Kepala Dusun tidak dapat melihat data dusun lain
 * dan Warga tidak dapat melihat NIK keluarga lain. Kegagalannya memblokir rilis.
 *
 * Pola harapan: objek DI LUAR lingkup tidak "ditolak" (403) melainkan
 * "tidak ada" (null -> 404). 403 akan memastikan bagi penyerang bahwa
 * objek itu memang ada.
 */
final class ScopeMatrixTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rtKrajan;

    private Region $rtSidomulyo;

    private Region $dusunKrajan;

    private Region $dusunSidomulyo;

    private Family $familyKrajan;

    private Family $familySidomulyo;

    private Resident $residentKrajan;

    private Resident $residentSidomulyo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $this->dusunSidomulyo = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        $this->rtKrajan = $this->firstRtOf($this->dusunKrajan);
        $this->rtSidomulyo = $this->firstRtOf($this->dusunSidomulyo);

        $this->familyKrajan = $this->makeFamily('3201010000000101', $this->rtKrajan);
        $this->familySidomulyo = $this->makeFamily('3201010000000102', $this->rtSidomulyo);

        $this->residentKrajan = $this->makeResident(
            $this->familyKrajan, $this->rtKrajan, '3201010101900101', 'Warga Krajan'
        );
        $this->residentSidomulyo = $this->makeResident(
            $this->familySidomulyo, $this->rtSidomulyo, '3201010101900102', 'Warga Sidomulyo'
        );
    }

    /* ===================== Kepala Dusun: pembatasan wilayah ================= */

    #[Test]
    #[TestDox('Kepala Dusun melihat penduduk dusunnya sendiri')]
    public function kadus_melihat_penduduk_dusunnya(): void
    {
        $repo = $this->residentRepoFor(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $this->assertNotNull(
            $repo->find($this->residentKrajan->id),
            'Kepala Dusun Krajan seharusnya dapat melihat penduduk dusunnya.'
        );
    }

    #[Test]
    #[TestDox('Kepala Dusun TIDAK melihat penduduk dusun lain (404, bukan 403)')]
    public function kadus_tidak_melihat_penduduk_dusun_lain(): void
    {
        $repo = $this->residentRepoFor(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $this->assertNull(
            $repo->find($this->residentSidomulyo->id),
            'KEBOCORAN DATA: Kepala Dusun Krajan dapat melihat penduduk Dusun Sidomulyo.'
        );
    }

    #[Test]
    #[TestDox('Pencarian Kepala Dusun tidak memuat penduduk dusun lain')]
    public function pencarian_kadus_tidak_membocorkan_dusun_lain(): void
    {
        $repo = $this->residentRepoFor(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $ids = collect($repo->search(null)->items())->pluck('id');

        $this->assertTrue($ids->contains($this->residentKrajan->id));
        $this->assertFalse(
            $ids->contains($this->residentSidomulyo->id),
            'KEBOCORAN DATA: hasil pencarian memuat penduduk dusun lain.'
        );
    }

    #[Test]
    #[TestDox('Pencarian NIK persis tetap tidak melewati batas wilayah')]
    public function pencarian_nik_persis_tetap_dibatasi_wilayah(): void
    {
        $repo = $this->residentRepoFor(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $this->assertNull(
            $repo->findByNik('3201010101900102'),
            'KEBOCORAN DATA: pencarian NIK persis melewati pembatasan wilayah. '
            .'Ini jalur kebocoran paling mudah terlewat.'
        );
    }

    #[Test]
    #[TestDox('Jumlah penduduk yang dilihat Kepala Dusun hanya dusunnya')]
    public function hitungan_kadus_hanya_dusunnya(): void
    {
        $krajan = $this->residentRepoFor(Role::KEPALA_DUSUN, $this->dusunKrajan);
        $sidomulyo = $this->residentRepoFor(Role::KEPALA_DUSUN, $this->dusunSidomulyo);

        $this->assertSame(1, $krajan->countActive());
        $this->assertSame(1, $sidomulyo->countActive());
    }

    #[Test]
    #[TestDox('Kepala Dusun TANPA wilayah ditugaskan tidak melihat apa pun (fail closed)')]
    public function kadus_tanpa_wilayah_tidak_melihat_apapun(): void
    {
        $repo = $this->residentRepoFor(Role::KEPALA_DUSUN, null);

        $this->assertSame(0, $repo->countActive(),
            'Kepala Dusun tanpa penugasan wilayah harus gagal tertutup (tidak '
            .'melihat apa pun), bukan gagal terbuka (melihat seluruh desa).'
        );
        $this->assertNull($repo->find($this->residentKrajan->id));
    }

    #[Test]
    #[TestDox('Kepala Dusun tidak melihat kartu keluarga dusun lain')]
    public function kadus_tidak_melihat_kk_dusun_lain(): void
    {
        $repo = $this->familyRepoFor(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $this->assertNotNull($repo->find($this->familyKrajan->id));
        $this->assertNull(
            $repo->find($this->familySidomulyo->id),
            'KEBOCORAN DATA: Kepala Dusun melihat KK dusun lain.'
        );
        $this->assertNull(
            $repo->findByKkNumber('3201010000000102'),
            'KEBOCORAN DATA: pencarian nomor KK melewati pembatasan wilayah.'
        );
    }

    /* ======================= Warga: pembatasan keluarga ===================== */

    #[Test]
    #[TestDox('Warga melihat anggota keluarganya sendiri')]
    public function warga_melihat_keluarganya(): void
    {
        $anggota = $this->makeResident(
            $this->familyKrajan, $this->rtKrajan, '3201014101950103', 'Istri Krajan', 'istri'
        );

        $repo = $this->residentRepoFor(
            Role::WARGA, null, $this->residentKrajan->id
        );

        $this->assertNotNull($repo->find($this->residentKrajan->id));
        $this->assertNotNull($repo->find($anggota->id));
    }

    #[Test]
    #[TestDox('Warga TIDAK melihat penduduk dari KK lain (RULE-11)')]
    public function warga_tidak_melihat_kk_lain(): void
    {
        $repo = $this->residentRepoFor(Role::WARGA, null, $this->residentKrajan->id);

        $this->assertNull(
            $repo->find($this->residentSidomulyo->id),
            'KEBOCORAN DATA: warga dapat melihat data penduduk keluarga lain. '
            .'Ini pelanggaran RULE-11 dan UU PDP.'
        );
        $this->assertNull(
            $repo->findByNik('3201010101900102'),
            'KEBOCORAN DATA: warga dapat mencari NIK warga lain.'
        );
    }

    #[Test]
    #[TestDox('Warga tanpa tautan data penduduk tidak melihat apa pun')]
    public function warga_tanpa_tautan_penduduk_tidak_melihat_apapun(): void
    {
        $repo = $this->residentRepoFor(Role::WARGA, null, null);

        $this->assertSame(0, $repo->countActive());
        $this->assertNull($repo->find($this->residentKrajan->id));
    }

    /* ======================== Anonim & peran tanpa batas =================== */

    #[Test]
    #[TestDox('Pengunjung anonim tidak melihat satu baris penduduk pun')]
    public function anonim_tidak_melihat_apapun(): void
    {
        $repo = new ResidentRepository(AccessScope::guest());

        $this->assertSame(0, $repo->countActive());
        $this->assertNull($repo->find($this->residentKrajan->id));
        $this->assertNull($repo->findByNik('3201010101900101'));
        $this->assertCount(0, $repo->search(null)->items());
    }

    #[Test]
    #[TestDox('Admin Desa melihat seluruh desa (tidak dibatasi wilayah)')]
    public function admin_desa_melihat_seluruh_desa(): void
    {
        $repo = $this->residentRepoFor(Role::ADMIN_DESA, null);

        $this->assertNotNull($repo->find($this->residentKrajan->id));
        $this->assertNotNull($repo->find($this->residentSidomulyo->id));
        $this->assertSame(2, $repo->countActive());
    }

    #[Test]
    #[TestDox('Pengawas melihat seluruh desa tetapi tidak punya izin tulis')]
    public function pengawas_hanya_baca(): void
    {
        $user = $this->userWithRole(Role::PENGAWAS);
        $scope = AccessScope::forUser($user);

        $this->assertTrue($scope->can('penduduk.lihat'));
        $this->assertFalse($scope->can('penduduk.tambah'), 'Pengawas tidak boleh menambah data.');
        $this->assertFalse($scope->can('penduduk.ubah'), 'Pengawas tidak boleh mengubah data.');
        $this->assertFalse($scope->can('penduduk.hapus'), 'Pengawas tidak boleh menghapus data.');
        $this->assertFalse($scope->can('surat.setujui'), 'Pengawas tidak boleh menyetujui surat.');
    }

    #[Test]
    #[TestDox('Operator tidak punya izin hapus maupun setujui (RULE-06)')]
    public function operator_tanpa_hak_hapus_dan_setujui(): void
    {
        $scope = AccessScope::forUser($this->userWithRole(Role::OPERATOR));

        $this->assertTrue($scope->can('penduduk.tambah'));
        $this->assertTrue($scope->can('penduduk.ubah'));
        $this->assertFalse($scope->can('penduduk.hapus'),
            'Operator tidak boleh menghapus penduduk (matriks RBAC docs/03 §4.9).');
        $this->assertFalse($scope->can('surat.setujui'),
            'Operator tidak boleh menyetujui surat — pemisahan kewenangan RULE-06.');
        $this->assertFalse($scope->can('surat.verifikasi'));
    }

    #[Test]
    #[TestDox('Hanya Admin Desa yang boleh melihat NIK penuh')]
    public function hanya_admin_desa_boleh_melihat_nik_penuh(): void
    {
        $allowed = [Role::ADMIN_DESA];
        $denied = [
            Role::KEPALA_DESA, Role::KASI_PEMERINTAHAN, Role::OPERATOR,
            Role::KEPALA_DUSUN, Role::WARGA, Role::PENGAWAS,
        ];

        foreach ($allowed as $role) {
            $this->assertTrue(
                AccessScope::forUser($this->userWithRole($role))->can('data_pribadi.lihat_penuh'),
                "Peran {$role} seharusnya boleh melihat NIK penuh."
            );
        }

        foreach ($denied as $role) {
            $this->assertFalse(
                AccessScope::forUser($this->userWithRole($role))->can('data_pribadi.lihat_penuh'),
                "KEBOCORAN: peran {$role} dapat melihat NIK penuh tanpa izin khusus."
            );
        }
    }

    /* ============================== Pembantu ============================== */

    private function residentRepoFor(
        string $role, ?Region $region = null, ?string $residentId = null
    ): ResidentRepository {
        return new ResidentRepository(
            AccessScope::forUser($this->userWithRole($role, $region, $residentId))
        );
    }

    private function familyRepoFor(string $role, ?Region $region = null): FamilyRepository
    {
        return new FamilyRepository(
            AccessScope::forUser($this->userWithRole($role, $region))
        );
    }

    private function firstRtOf(Region $dusun): Region
    {
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();

        return Region::query()->where('parent_id', $rw->id)->firstOrFail();
    }

    private function makeFamily(string $kkNumber, Region $region): Family
    {
        $family = new Family;
        $family->setKkNumber($kkNumber);
        $family->forceFill([
            'region_id' => $region->id,
            'address' => 'Alamat Uji',
        ])->save();

        return $family;
    }

    private function makeResident(
        Family $family, Region $region, string $nik, string $name, string $relation = 'kepala_keluarga'
    ): Resident {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $region->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1990-01-01',
            'gender' => 'L',
            'family_relation_code' => $relation,
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }
}
