<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Models\Family;
use App\Models\Letter;
use App\Models\LetterMutationOffer;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\ResidentMutation;
use App\Models\Role;
use App\Support\NameNormalizer;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Tawaran mutasi dari SKL/SKM — docs/13 §5.4 & §5.5, KEP-015, E2E-12 & E2E-13.
 *
 * YANG DIJAGA uji ini, di atas segalanya: tawaran TETAP TAWARAN. Penerbitan
 * surat tidak pernah mengubah status penduduk dengan sendirinya, dan penolakan
 * tawaran tidak pernah menghalangi suratnya. Bila suatu saat ada yang
 * "menyederhanakan" ini menjadi otomatis, uji di kelas inilah yang gagal.
 */
final class MutationOfferTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rt;

    private Family $family;

    private LetterType $skl;

    private LetterType $skm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000008001');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Mutasi 7',
        ])->save();

        $this->skl = LetterType::query()->where('code', 'SKL')->firstOrFail();
        $this->skm = LetterType::query()->where('code', 'SKM')->firstOrFail();
    }

    /* ===================== Pembuatan tawaran ===================== */

    #[Test]
    #[TestDox('SKL terbit → tawaran mutasi kelahiran menunggu, dan TIDAK ada penduduk baru dibuat')]
    public function skl_terbit_menawarkan_mutasi_kelahiran(): void
    {
        $pemohon = $this->makeResident('3201010101800401', 'Ibu Pemohon Skl');
        $request = $this->makeSklRequest($pemohon);

        $jumlahPendudukSebelum = Resident::query()->count();

        $this->issue($request);

        $offer = LetterMutationOffer::query()->where('request_id', $request->id)->firstOrFail();

        $this->assertSame(ResidentMutation::TYPE_BIRTH, $offer->mutation_type);
        $this->assertSame(LetterMutationOffer::STATUS_PENDING, $offer->status);
        $this->assertNull($offer->resident_mutation_id);
        $this->assertNull($offer->decided_at);

        $this->assertSame(
            $jumlahPendudukSebelum,
            Resident::query()->count(),
            'Penerbitan SKL tidak boleh mendaftarkan bayi dengan sendirinya — '
            .'kata "menawarkan" di docs/13 §5.4 disengaja.'
        );
        $this->assertDatabaseCount('resident_mutations', 0);
    }

    #[Test]
    #[TestDox('SKM terbit → tawaran mutasi kematian menunggu, status almarhum MASIH aktif')]
    public function skm_terbit_menawarkan_mutasi_kematian(): void
    {
        $almarhum = $this->makeResident('3201010101500402', 'Mbah Almarhum');
        $pelapor = $this->makeResident('3201010101800403', 'Anak Pelapor');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $this->issue($request);

        $offer = LetterMutationOffer::query()->where('request_id', $request->id)->firstOrFail();

        $this->assertSame(ResidentMutation::TYPE_DEATH, $offer->mutation_type);
        $this->assertTrue($offer->isPending());

        $this->assertSame(
            Resident::STATUS_ACTIVE,
            $almarhum->fresh()->status,
            'Penerbitan SKM tidak boleh mengubah status penduduk dengan sendirinya.'
        );
    }

    #[Test]
    #[TestDox('Jenis surat lain (SKTM) tidak menawarkan mutasi apa pun')]
    public function jenis_surat_lain_tidak_menawarkan_mutasi(): void
    {
        $sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();
        $pemohon = $this->makeResident('3201010101800404', 'Pemohon Sktm');

        $request = $this->makeRequest($sktm, $pemohon, [
            'form_data' => ['keperluan' => 'Pengajuan beasiswa'],
        ]);

        $this->issue($request);

        $this->assertDatabaseCount('letter_mutation_offers', 0);
    }

    #[Test]
    #[TestDox('Tawaran tampil pada layar detail pengajuan, beserta peringatan kepala keluarga (RULE-02)')]
    public function tawaran_tampil_di_layar_detail(): void
    {
        $almarhum = $this->makeResident('3201010101500405', 'Kepala Keluarga Wafat', [
            'family_relation_code' => 'kepala_keluarga',
        ]);
        $pelapor = $this->makeResident('3201010101800406', 'Istri Pelapor');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $this->issue($request);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/{$request->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mutation_offer.mutation_type', ResidentMutation::TYPE_DEATH)
                ->where('mutation_offer.is_pending', true)
                ->where('mutation_offer.target.full_name', 'Kepala Keluarga Wafat')
                ->where('mutation_offer.target.is_head_of_family', true));
    }

    /* ===================== Menerima: kematian ===================== */

    #[Test]
    #[TestDox('E2E-13: tawaran kematian diterima → status meninggal, mutasi tertaut ke suratnya')]
    public function tawaran_kematian_diterima(): void
    {
        $almarhum = $this->makeResident('3201010101500407', 'Pak Wafat');
        $pelapor = $this->makeResident('3201010101800408', 'Anak Pelapor Dua');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $letter = $this->issue($request);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/tawaran-mutasi")
            ->assertRedirect("/surat/{$request->id}");

        $this->assertSame('meninggal', $almarhum->fresh()->status);

        $mutation = ResidentMutation::query()
            ->where('resident_id', $almarhum->id)
            ->firstOrFail();

        $this->assertSame(ResidentMutation::TYPE_DEATH, $mutation->type);
        $this->assertSame('2026-02-10', $mutation->mutation_date->toDateString());
        $this->assertSame('Sakit', $mutation->death_cause);
        $this->assertSame('RSUD Sukamaju', $mutation->death_place);
        $this->assertSame(
            $letter->id,
            $mutation->letter_id,
            'Mutasi harus menyimpan surat yang menjadi dasarnya, agar '
            .'"atas dasar apa status warga ini berubah?" dapat dijawab.'
        );
        $this->assertSame(
            $letter->letter_number,
            $mutation->document_no,
            'Dokumen pendukung adalah nomor surat desa, bukan nomor surat rumah sakit.'
        );
        $this->assertStringContainsString('Anak Pelapor Dua', (string) $mutation->reported_by);
        $this->assertStringContainsString('anak', (string) $mutation->reported_by);

        $offer = LetterMutationOffer::query()->where('request_id', $request->id)->firstOrFail();

        $this->assertSame(LetterMutationOffer::STATUS_ACCEPTED, $offer->status);
        $this->assertSame($mutation->id, $offer->resident_mutation_id);
        $this->assertNotNull($offer->decided_at);
        $this->assertNotNull($offer->decided_by_name);

        // RULE-03: hilang dari statistik penduduk aktif.
        $this->assertDatabaseHas('residents', [
            'id' => $almarhum->id, 'status' => 'meninggal',
        ]);
    }

    #[Test]
    #[TestDox('Penduduk yang sudah meninggal tidak dapat mengajukan surat lagi (RULE-03)')]
    public function almarhum_tidak_dapat_mengajukan_surat_lagi(): void
    {
        $almarhum = $this->makeResident('3201010101500409', 'Pak Wafat Dua');
        $pelapor = $this->makeResident('3201010101800410', 'Pelapor Tiga');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $this->issue($request);

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/tawaran-mutasi")
            ->assertRedirect();

        $sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();

        $response = $this->actingAs($admin)->post('/surat', [
            'letter_type_id' => $sktm->id,
            'resident_id' => $almarhum->id,
            'form' => ['keperluan' => 'Pengajuan beasiswa'],
        ]);

        $response->assertSessionHasErrors('resident_id');
        $this->assertStringContainsString(
            'meninggal',
            $this->errorMessage($response, 'resident_id'),
        );
    }

    /* ===================== Menerima: kelahiran ===================== */

    #[Test]
    #[TestDox('E2E-12: tawaran kelahiran diterima → anak masuk KK pemohon & mutasi lahir dicatat')]
    public function tawaran_kelahiran_diterima(): void
    {
        $pemohon = $this->makeResident('3201010101800411', 'Bapak Pemohon Skl');
        $request = $this->makeSklRequest($pemohon);

        $letter = $this->issue($request);

        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post("/surat/{$request->id}/tawaran-mutasi", [
                'nik' => '3201 0110 0226 0001',
                'full_name' => 'Ahmad Fatih Nugraha',
            ])
            ->assertRedirect();

        $anak = Resident::query()->where('full_name', 'Ahmad Fatih Nugraha')->firstOrFail();

        $this->assertSame($this->family->id, $anak->family_id,
            'Bayi harus masuk ke kartu keluarga PEMOHON.');
        $this->assertSame('anak', $anak->family_relation_code);
        $this->assertSame('L', $anak->gender, '"Laki-laki" di formulir surat → kode L.');
        $this->assertSame('2026-02-26', $anak->birth_date->toDateString());
        $this->assertSame('Sukamaju', $anak->birth_place);
        $this->assertSame('Bapak Pemohon Skl', $anak->father_name);
        $this->assertSame('Siti Aminah', $anak->mother_name);
        $this->assertSame($this->rt->id, $anak->region_id,
            'Wilayah diwarisi dari pemohon — tanpa itu bayi hilang dari daftar Kepala Dusun.');
        $this->assertSame(Resident::STATUS_ACTIVE, $anak->status);

        $mutation = ResidentMutation::query()->where('resident_id', $anak->id)->firstOrFail();

        $this->assertSame(ResidentMutation::TYPE_BIRTH, $mutation->type);
        $this->assertSame('2026-02-26', $mutation->mutation_date->toDateString());
        $this->assertSame('3.20', $mutation->birth_weight);
        $this->assertSame('49.00', $mutation->birth_length);
        $this->assertSame('Bidan', $mutation->birth_helper);
        $this->assertSame($letter->id, $mutation->letter_id);

        $offer = LetterMutationOffer::query()->where('request_id', $request->id)->firstOrFail();

        $this->assertSame(LetterMutationOffer::STATUS_ACCEPTED, $offer->status);
        $this->assertSame($mutation->id, $offer->resident_mutation_id);
    }

    #[Test]
    #[TestDox('NIK bayi wajib dan harus sah — tidak boleh diisi angka sementara (RULE-01)')]
    public function nik_bayi_wajib_dan_sah(): void
    {
        $pemohon = $this->makeResident('3201010101800412', 'Pemohon Skl Dua');
        $request = $this->makeSklRequest($pemohon);

        $this->issue($request);

        $operator = $this->userWithRole(Role::OPERATOR);

        $this->actingAs($operator)
            ->post("/surat/{$request->id}/tawaran-mutasi", ['full_name' => 'Bayi Tanpa Nik'])
            ->assertSessionHasErrors('nik');

        $this->actingAs($operator)
            ->post("/surat/{$request->id}/tawaran-mutasi", [
                'nik' => '123', 'full_name' => 'Bayi Nik Pendek',
            ])
            ->assertSessionHasErrors('nik');

        $this->assertDatabaseCount('resident_mutations', 0);
        $this->assertSame(
            LetterMutationOffer::STATUS_PENDING,
            LetterMutationOffer::query()->where('request_id', $request->id)->firstOrFail()->status,
        );
    }

    #[Test]
    #[TestDox('NIK bayi yang sudah dipakai penduduk lain ditolak (RULE-01)')]
    public function nik_bayi_ganda_ditolak(): void
    {
        $pemohon = $this->makeResident('3201010101800413', 'Pemohon Skl Tiga');
        $request = $this->makeSklRequest($pemohon);

        $this->issue($request);

        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post("/surat/{$request->id}/tawaran-mutasi", [
                'nik' => '3201010101800413',
                'full_name' => 'Bayi Nik Ganda',
            ])
            ->assertSessionHasErrors('nik');

        $this->assertDatabaseCount('resident_mutations', 0);
    }

    #[Test]
    #[TestDox('Layar penerimaan kelahiran terisi dari formulir surat — satu klik, data sudah ada')]
    public function layar_kelahiran_terisi_dari_formulir_surat(): void
    {
        $pemohon = $this->makeResident('3201010101800414', 'Pemohon Skl Empat');
        $request = $this->makeSklRequest($pemohon);

        $this->issue($request);

        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->get("/surat/{$request->id}/tawaran-mutasi")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Surat/TawaranMutasi/Kelahiran')
                ->where('prefill.full_name', 'Ahmad Fatih')
                ->where('prefill.gender_label', 'Laki-laki')
                ->where('prefill.birth_date', '2026-02-26')
                ->where('prefill.birth_weight', '3.2')
                ->where('request.family_kk_masked', $this->family->kk_number_masked));
    }

    /* ===================== Menolak tawaran ===================== */

    #[Test]
    #[TestDox('Tawaran ditolak: tidak ada mutasi, dan SURAT TETAP SAH — penolakan tidak menghalangi penerbitan')]
    public function tawaran_dapat_ditolak_tanpa_mengganggu_surat(): void
    {
        $almarhum = $this->makeResident('3201010101500415', 'Pak Wafat Tiga');
        $pelapor = $this->makeResident('3201010101800416', 'Pelapor Empat');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $letter = $this->issue($request);

        $this->actingAs($this->userWithRole(Role::KASI_PEMERINTAHAN))
            ->post("/surat/{$request->id}/tawaran-mutasi/tolak", [
                'reason' => 'Kematian sudah dicatat lewat modul kependudukan.',
            ])
            ->assertRedirect();

        $offer = LetterMutationOffer::query()->where('request_id', $request->id)->firstOrFail();

        $this->assertSame(LetterMutationOffer::STATUS_DECLINED, $offer->status);
        $this->assertSame(
            'Kematian sudah dicatat lewat modul kependudukan.',
            $offer->decline_reason,
        );
        $this->assertNotNull($offer->decided_at);

        $this->assertSame(Resident::STATUS_ACTIVE, $almarhum->fresh()->status);
        $this->assertDatabaseCount('resident_mutations', 0);

        $letter->refresh();
        $this->assertSame(Letter::STATUS_VALID, $letter->status);
        $this->assertSame(LetterRequest::STATUS_ISSUED, $request->fresh()->status);
    }

    #[Test]
    #[TestDox('Tawaran yang sudah diputuskan tidak dapat diputuskan dua kali → 409')]
    public function tawaran_tidak_dapat_diputuskan_dua_kali(): void
    {
        $almarhum = $this->makeResident('3201010101500417', 'Pak Wafat Empat');
        $pelapor = $this->makeResident('3201010101800418', 'Pelapor Lima');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $this->issue($request);

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/tawaran-mutasi/tolak")
            ->assertRedirect();

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/tawaran-mutasi")
            ->assertStatus(409);

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/tawaran-mutasi/tolak")
            ->assertStatus(409);

        $this->assertDatabaseCount('resident_mutations', 0);
    }

    /* ===================== Pagar ===================== */

    #[Test]
    #[TestDox('Surat yang dibatalkan tidak dapat lagi menjadi dasar mutasi (RULE-05) → 409')]
    public function surat_dibatalkan_tidak_dapat_menjadi_dasar_mutasi(): void
    {
        $almarhum = $this->makeResident('3201010101500419', 'Pak Wafat Lima');
        $pelapor = $this->makeResident('3201010101800420', 'Pelapor Enam');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $letter = $this->issue($request);

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->post("/surat/arsip/{$letter->id}/batalkan", ['reason' => 'Salah orang.'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/tawaran-mutasi")
            ->assertStatus(409);

        $this->assertSame(Resident::STATUS_ACTIVE, $almarhum->fresh()->status);
    }

    #[Test]
    #[TestDox('Tanpa izin mutasi.tambah, tawaran tidak dapat diterima maupun ditolak → 403')]
    public function tanpa_izin_mutasi_tawaran_tidak_dapat_diputuskan(): void
    {
        $almarhum = $this->makeResident('3201010101500421', 'Pak Wafat Enam');
        $pelapor = $this->makeResident('3201010101800422', 'Pelapor Tujuh');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $this->issue($request);

        // Kepala Desa menyetujui surat tetapi TIDAK memegang `mutasi.tambah`.
        $kades = $this->userWithRole(Role::KEPALA_DESA);

        $this->actingAs($kades)
            ->post("/surat/{$request->id}/tawaran-mutasi")
            ->assertForbidden();

        $this->actingAs($kades)
            ->post("/surat/{$request->id}/tawaran-mutasi/tolak")
            ->assertForbidden();

        $this->assertSame(Resident::STATUS_ACTIVE, $almarhum->fresh()->status);
    }

    #[Test]
    #[TestDox('Kelahiran memerlukan izin penduduk.tambah tersendiri → Kasi Pemerintahan ditolak 403')]
    public function kelahiran_memerlukan_izin_pendaftaran_penduduk(): void
    {
        $pemohon = $this->makeResident('3201010101800423', 'Pemohon Skl Lima');
        $request = $this->makeSklRequest($pemohon);

        $this->issue($request);

        // Kasi Pemerintahan: `mutasi.tambah` ada, `penduduk.tambah` tidak.
        $kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);

        $this->actingAs($kasi)
            ->get("/surat/{$request->id}/tawaran-mutasi")
            ->assertForbidden();

        $this->actingAs($kasi)
            ->post("/surat/{$request->id}/tawaran-mutasi", [
                'nik' => '3201011002260002', 'full_name' => 'Bayi Tanpa Izin',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('resident_mutations', 0);

        // Menolak tetap boleh — itu kewenangan mutasi, bukan pendaftaran.
        $this->actingAs($kasi)
            ->post("/surat/{$request->id}/tawaran-mutasi/tolak")
            ->assertRedirect();
    }

    #[Test]
    #[TestDox('Pengajuan di luar wilayah kewenangan menghasilkan 404, bukan 403 (docs/06 §4)')]
    public function pengajuan_di_luar_lingkup_menghasilkan_404(): void
    {
        $pemohon = $this->makeResident('3201010101800424', 'Pemohon Skl Enam');
        $request = $this->makeSklRequest($pemohon);

        $this->issue($request);

        /*
         * Pengguna ini SENGAJA berperan ganda: Kepala Dusun (satu-satunya
         * peran yang dibatasi wilayah — lihat Role::REGION_LIMITED_ROLES) di
         * dusun LAIN, ditambah Operator yang membawa izin mutasi.tambah dan
         * penduduk.tambah.
         *
         * Tanpa peran kedua itu, permintaan sudah berhenti di middleware
         * `izin` dengan 403 dan uji ini tidak akan pernah menyentuh lapis
         * repository — yaitu justru hal yang hendak dibuktikan: objek di luar
         * lingkup menjawab 404, bukan 403 yang akan memastikan bagi penyerang
         * bahwa pengajuannya memang ada.
         */
        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
        $asing = $this->userWithRole(Role::KEPALA_DUSUN, $dusunLain);
        $asing->roles()->attach(
            Role::query()->where('code', Role::OPERATOR)->firstOrFail()->id,
        );
        $asing = $asing->fresh(['roles.permissions', 'resident']);

        $this->actingAs($asing)
            ->get("/surat/{$request->id}/tawaran-mutasi")
            ->assertNotFound();

        $this->actingAs($asing)
            ->post("/surat/{$request->id}/tawaran-mutasi", [
                'nik' => '3201011002260003', 'full_name' => 'Bayi Dusun Lain',
            ])
            ->assertNotFound();

        $this->actingAs($asing)
            ->post("/surat/{$request->id}/tawaran-mutasi/tolak")
            ->assertNotFound();

        $this->assertDatabaseCount('resident_mutations', 0);
        $this->assertSame(
            LetterMutationOffer::STATUS_PENDING,
            LetterMutationOffer::query()->where('request_id', $request->id)->firstOrFail()->status,
        );
    }

    #[Test]
    #[TestDox('Almarhum di luar lingkup petugas: tawaran tidak dapat diterima → 404, dan panel tanpa data warga')]
    public function almarhum_di_luar_lingkup_menghasilkan_404(): void
    {
        /*
         * Almarhum tinggal di dusun lain, pelapornya di dusun petugas ini.
         * `resident_meninggal_id` hanyalah ISIAN FORMULIR — lingkupnya belum
         * pernah diperiksa saat surat dibuat, jadi ia wajib diperiksa di sini.
         */
        $rtLain = $this->rtOfOtherDusun();

        $keluargaLain = new Family;
        $keluargaLain->setKkNumber('3201010000008002');
        $keluargaLain->forceFill([
            'region_id' => $rtLain->id, 'address' => 'Jl. Sidomulyo 3',
        ])->save();

        $almarhum = $this->makeResident('3201010101500425', 'Warga Dusun Lain', [
            'family_id' => $keluargaLain->id,
            'region_id' => $rtLain->id,
        ]);
        $pelapor = $this->makeResident('3201010101800426', 'Pelapor Delapan');
        $request = $this->makeSkmRequest($pelapor, $almarhum);

        $this->issue($request);

        $petugas = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunOf($this->rt));
        $petugas->roles()->attach(
            Role::query()->where('code', Role::OPERATOR)->firstOrFail()->id,
        );
        $petugas = $petugas->fresh(['roles.permissions', 'resident']);

        // Pengajuannya sendiri TERLIHAT — pemohonnya warga dusun ini.
        $this->actingAs($petugas)
            ->get("/surat/{$request->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mutation_offer.is_pending', true)
                ->where('mutation_offer.target', null));

        // Tetapi mutasinya tidak dapat dicatat dari sini.
        $this->actingAs($petugas)
            ->post("/surat/{$request->id}/tawaran-mutasi")
            ->assertNotFound();

        $this->assertSame(Resident::STATUS_ACTIVE, $almarhum->fresh()->status);
    }

    /* ===================== Pembantu ===================== */

    private function issue(LetterRequest $request): Letter
    {
        $request->forceFill([
            'status' => LetterRequest::STATUS_VERIFIED,
            'current_step' => $request->letterType->verificationStepCount(),
        ])->save();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect();

        return Letter::query()->where('request_id', $request->id)->firstOrFail();
    }

    private function makeSklRequest(Resident $pemohon): LetterRequest
    {
        return $this->makeRequest($this->skl, $pemohon, [
            'form_data' => [
                'nama_anak' => 'Ahmad Fatih',
                'jenis_kelamin_anak' => 'Laki-laki',
                'tempat_lahir_anak' => 'Sukamaju',
                'tanggal_lahir_anak' => '2026-02-26',
                'anak_ke' => 2,
                'berat_badan' => 3.2,
                'panjang_badan' => 49,
                'penolong_kelahiran' => 'Bidan',
                'nama_ayah' => $pemohon->full_name,
                'nik_ayah' => '3201010101800411',
                'nama_ibu' => 'Siti Aminah',
                'nik_ibu' => '3201010101850001',
            ],
        ]);
    }

    private function makeSkmRequest(Resident $pelapor, Resident $almarhum): LetterRequest
    {
        return $this->makeRequest($this->skm, $pelapor, [
            'form_data' => [
                'resident_meninggal_id' => $almarhum->id,
                'tanggal_kematian' => '2026-02-10',
                'tempat_kematian' => 'RSUD Sukamaju',
                'sebab_kematian' => 'Sakit',
                'hubungan_pelapor' => 'anak',
            ],
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeRequest(
        LetterType $type,
        Resident $resident,
        array $overrides = [],
    ): LetterRequest {
        $request = LetterRequest::query()->create([
            'letter_type_id' => $type->id,
            'ticket_number' => 'T'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
            'resident_id' => $resident->id,
            'channel' => 'walk_in',
            'purpose' => null,
            'form_data' => [],
            'status' => LetterRequest::STATUS_SUBMITTED,
            'current_step' => 0,
            'submitted_at' => now(),
            'due_at' => now()->addDay(),
            ...$overrides,
        ]);

        $request->setRelation('letterType', $type);

        return $request;
    }

    /** Dusun tempat satu RT berada — RT → RW → dusun. */
    private function dusunOf(Region $rt): Region
    {
        $rw = Region::query()->findOrFail($rt->parent_id);

        return Region::query()->findOrFail($rw->parent_id);
    }

    /** Satu RT di dusun selain dusun milik $this->rt. */
    private function rtOfOtherDusun(): Region
    {
        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusunLain->id)->firstOrFail();

        return Region::query()->where('parent_id', $rw->id)->firstOrFail();
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeResident(string $nik, string $name, array $overrides = []): Resident
    {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $this->family->id,
            'region_id' => $this->rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_place' => 'Sukamaju',
            'birth_date' => '1960-03-17',
            'gender' => 'L',
            'religion_code' => 'islam',
            'marital_status_code' => 'kawin',
            'occupation_code' => 'petani',
            'family_relation_code' => 'anak',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
            ...$overrides,
        ])->save();

        return $resident;
    }
}
