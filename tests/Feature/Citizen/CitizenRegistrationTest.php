<?php

declare(strict_types=1);

namespace Tests\Feature\Citizen;

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Registrasi & verifikasi akun warga — FR-AUT-08/09/10, UC-15, BR-10, KEP-017.
 *
 * YANG PALING DIJAGA KELAS INI adalah anti-enumerasi. Formulir pendaftaran
 * mencocokkan NIK ke data penduduk; bila jawabannya berbeda antara "NIK tidak
 * terdaftar", "NIK sudah punya akun", dan "berhasil", maka siapa pun dapat
 * memeriksa apakah seseorang warga desa ini — dan, dengan mencoba NIK
 * berurutan, menyusun daftar penduduk desa tanpa pernah masuk sistem.
 */
final class CitizenRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private const NIK = '3201010101850001';

    private const KK = '3201010000008801';

    private const NAMA = 'Siti Rohmah';

    private Region $rt;

    private Family $family;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        Storage::fake('local');

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber(self::KK);
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Pendaftaran 7',
        ])->save();

        $this->resident = $this->makeResident(self::NIK, self::NAMA);
    }

    /* ============================ Anti-enumerasi ========================== */

    #[Test]
    #[TestDox('FR-AUT-10: data cocok, tidak cocok, dan sudah terdaftar berakhir di halaman yang sama persis')]
    public function jawaban_selalu_sama(): void
    {
        $cocok = $this->post('/daftar', $this->payload());
        $cocok->assertRedirect('/daftar/terkirim')->assertSessionHasNoErrors();

        $tidakCocok = $this->post('/daftar', $this->payload([
            'nik' => '3201019999999999',
            'email' => 'lain@warga.test',
        ]));
        $tidakCocok->assertRedirect('/daftar/terkirim')->assertSessionHasNoErrors();

        // Pendaftaran kedua untuk penduduk yang sama — sudah punya akun.
        $ulang = $this->post('/daftar', $this->payload(['email' => 'ketiga@warga.test']));
        $ulang->assertRedirect('/daftar/terkirim')->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()->whereNotNull('resident_id')->count(),
            'Pendaftaran yang tidak cocok atau berulang tetap membuat akun.');
    }

    #[Test]
    #[TestDox('Nama yang tidak cocok ditolak diam-diam — NIK saja tidak cukup untuk membuat akun')]
    public function nama_harus_cocok(): void
    {
        $this->post('/daftar', $this->payload(['full_name' => 'Orang Lain']))
            ->assertRedirect('/daftar/terkirim');

        $this->assertSame(0, User::query()->whereNotNull('resident_id')->count());
    }

    #[Test]
    #[TestDox('Nomor KK yang bukan milik penduduk itu ditolak')]
    public function kk_harus_milik_penduduk_itu(): void
    {
        $lain = new Family;
        $lain->setKkNumber('3201010000008802');
        $lain->forceFill(['region_id' => $this->rt->id, 'address' => 'Jl. Lain 1'])->save();

        $this->post('/daftar', $this->payload(['kk_number' => '3201010000008802']))
            ->assertRedirect('/daftar/terkirim');

        $this->assertSame(0, User::query()->whereNotNull('resident_id')->count());
    }

    #[Test]
    #[TestDox('RULE-03: penduduk meninggal atau pindah tidak dapat memperoleh akun')]
    public function penduduk_tidak_aktif_ditolak(): void
    {
        $this->resident->forceFill(['status' => 'pindah_keluar'])->save();

        $this->post('/daftar', $this->payload())->assertRedirect('/daftar/terkirim');

        $this->assertSame(0, User::query()->whereNotNull('resident_id')->count(),
            'Akun terbit atas nama orang yang tidak lagi berdomisili di desa ini.');
    }

    #[Test]
    #[TestDox('Percobaan yang tidak cocok tercatat di audit log dengan NIK ter-hash, bukan terbaca')]
    public function percobaan_gagal_tercatat_tanpa_nik_terbaca(): void
    {
        $this->post('/daftar', $this->payload(['nik' => '3201019999999999']))
            ->assertRedirect('/daftar/terkirim');

        $entry = AuditLog::query()->where('action', 'citizen.registration_unmatched')->first();

        $this->assertNotNull($entry, 'Percobaan pendaftaran gagal tidak tercatat sama sekali.');

        $context = json_encode($entry->context) ?: '';
        $this->assertStringNotContainsString('3201019999999999', $context,
            'NIK tersimpan terbaca di audit log.');
    }

    /* ======================== Akun yang berhasil dibuat =================== */

    #[Test]
    #[TestDox('Akun baru berstatus menunggu verifikasi, berperan warga, dan belum dapat dipakai masuk')]
    public function akun_baru_belum_dapat_masuk(): void
    {
        $this->post('/daftar', $this->payload())->assertRedirect('/daftar/terkirim');

        $user = User::query()->whereNotNull('resident_id')->firstOrFail();

        $this->assertSame(User::STATUS_PENDING, $user->status);
        $this->assertSame($this->resident->id, $user->resident_id);
        $this->assertTrue($user->roles->contains('code', Role::WARGA));

        /*
         * LoginController menolak setiap akun yang bukan `aktif` dengan pesan
         * yang sama seperti kata sandi salah — jadi akun yang menunggu pun
         * tidak dapat dipakai memeriksa apakah pendaftaran berhasil.
         */
        $this->post('/masuk', [
            'identifier' => 'siti@warga.test',
            'password' => 'kata-sandi-warga-2026',
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    #[Test]
    #[TestDox('Harus ada email atau nomor ponsel — ini tentang data pemohon sendiri, jadi boleh dikatakan terang')]
    public function salah_satu_kontak_wajib(): void
    {
        $this->post('/daftar', $this->payload(['email' => '', 'phone' => '']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::query()->whereNotNull('resident_id')->count());
    }

    #[Test]
    #[TestDox('Memilih unggah KTP tanpa melampirkan berkas ditolak dengan pesan yang jelas')]
    public function unggah_ktp_tanpa_berkas_ditolak(): void
    {
        $this->post('/daftar', $this->payload(['verification_method' => 'ktp_upload']))
            ->assertSessionHasErrors('ktp');
    }

    #[Test]
    #[TestDox('Verifikasi tatap muka tidak menuntut unggahan apa pun')]
    public function tatap_muka_tanpa_unggahan(): void
    {
        $this->post('/daftar', $this->payload(['verification_method' => 'tatap_muka']))
            ->assertRedirect('/daftar/terkirim');

        $user = User::query()->whereNotNull('resident_id')->firstOrFail();

        $this->assertSame('tatap_muka', $user->verification_method);
        $this->assertNull($user->verification_document_path);
    }

    #[Test]
    #[TestDox('Berkas KTP diperiksa dari ISINYA, bukan dari nama berkasnya')]
    public function berkas_ktp_diperiksa_dari_isi(): void
    {
        $this->post('/daftar', [
            ...$this->payload(['verification_method' => 'ktp_upload']),
            'ktp' => UploadedFile::fake()->createWithContent('ktp.jpg', '<?php echo 1;'),
        ])->assertSessionHasErrors('ktp');

        $this->assertSame(0, User::query()->whereNotNull('resident_id')->count());
    }

    /* ========================= Verifikasi petugas ========================= */

    #[Test]
    #[TestDox('Admin Desa menyetujui: akun aktif, dapat masuk, dan foto KTP dihapus')]
    public function persetujuan_mengaktifkan_dan_menghapus_ktp(): void
    {
        $user = $this->daftarDenganKtp();
        $path = $user->verification_document_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post('/pengaturan/verifikasi-warga/'.$user->id.'/setujui')
            ->assertRedirect();

        $user->refresh();

        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->verified_at);
        $this->assertNull($user->verification_document_path);

        /*
         * Foto KTP benar-benar dihapus dari penyimpanan, bukan hanya kolomnya
         * dikosongkan. Menyimpannya "untuk jaga-jaga" berarti desa memelihara
         * arsip foto KTP seluruh warganya (docs/08 §3, minimalisasi data).
         */
        Storage::disk('local')->assertMissing($path);

        $this->post('/keluar');

        $this->post('/masuk', [
            'identifier' => 'siti@warga.test',
            'password' => 'kata-sandi-warga-2026',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    #[TestDox('Penolakan menuntut alasan, menonaktifkan akun, dan menyimpan alasannya untuk dibacakan')]
    public function penolakan_menyimpan_alasan(): void
    {
        $user = $this->daftarDenganKtp();
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->post('/pengaturan/verifikasi-warga/'.$user->id.'/tolak')
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post('/pengaturan/verifikasi-warga/'.$user->id.'/tolak', [
                'reason' => 'Nama pada KTP berbeda dengan data kependudukan',
            ])
            ->assertRedirect();

        $user->refresh();

        $this->assertSame(User::STATUS_DISABLED, $user->status);
        $this->assertStringContainsString('berbeda', (string) $user->verification_note);
        $this->assertNull($user->verification_document_path);
    }

    #[Test]
    #[TestDox('Akun petugas tidak dapat diaktifkan lewat rute verifikasi warga')]
    public function rute_verifikasi_tidak_mengaktifkan_akun_petugas(): void
    {
        $petugas = $this->userWithRole(Role::OPERATOR);
        $petugas->forceFill(['status' => User::STATUS_PENDING])->save();

        /*
         * 404, bukan 403: tanpa pagar ini rute verifikasi warga menjadi cara
         * mengaktifkan akun apa pun yang kebetulan berstatus menunggu —
         * termasuk akun petugas yang sengaja dinonaktifkan.
         */
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post('/pengaturan/verifikasi-warga/'.$petugas->id.'/setujui')
            ->assertNotFound();

        $this->assertSame(User::STATUS_PENDING, $petugas->fresh()?->status);
    }

    #[Test]
    #[TestDox('Peran tanpa izin pengguna.verifikasi tidak dapat membuka maupun menyetujui')]
    public function izin_verifikasi_ditegakkan(): void
    {
        $user = $this->daftarDenganKtp();
        $operator = $this->userWithRole(Role::OPERATOR);

        $this->actingAs($operator)->get('/pengaturan/verifikasi-warga')->assertForbidden();
        $this->actingAs($operator)
            ->post('/pengaturan/verifikasi-warga/'.$user->id.'/setujui')
            ->assertForbidden();

        $this->assertSame(User::STATUS_PENDING, $user->fresh()?->status);
    }

    #[Test]
    #[TestDox('Membuka foto KTP tercatat sebagai pembukaan data pribadi (RULE-07)')]
    public function pembukaan_ktp_tercatat(): void
    {
        $user = $this->daftarDenganKtp();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get('/pengaturan/verifikasi-warga/'.$user->id.'/dokumen')
            ->assertOk();

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'citizen.verification_document_viewed')
            ->count());
    }

    #[Test]
    #[TestDox('Antrean verifikasi menampilkan NIK ter-masking, bukan enam belas digit')]
    public function antrean_menampilkan_nik_tersamar(): void
    {
        $this->daftarDenganKtp();

        $body = (string) $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get('/pengaturan/verifikasi-warga')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::NIK, $body,
            'NIK penuh terkirim ke layar verifikasi; RULE-07 menuntutnya ter-masking.');
        $this->assertStringContainsString('Siti Rohmah', $body);
    }

    /* ================================ Bantu =============================== */

    /** Daftarkan warga dengan foto KTP, kembalikan akunnya. */
    private function daftarDenganKtp(): User
    {
        $this->post('/daftar', [
            ...$this->payload(['verification_method' => 'ktp_upload']),
            'ktp' => UploadedFile::fake()->createWithContent('ktp.jpg', "\xFF\xD8\xFF".str_repeat('a', 128)),
        ])->assertRedirect('/daftar/terkirim');

        return User::query()->whereNotNull('resident_id')->firstOrFail();
    }

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return [
            'nik' => self::NIK,
            'kk_number' => self::KK,
            'full_name' => self::NAMA,
            'email' => 'siti@warga.test',
            'phone' => '',
            'password' => 'kata-sandi-warga-2026',
            'password_confirmation' => 'kata-sandi-warga-2026',
            'verification_method' => 'tatap_muka',
            'setuju' => '1',
            ...$override,
        ];
    }

    private function makeResident(string $nik, string $name): Resident
    {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $this->family->id,
            'region_id' => $this->rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1985-01-01',
            'gender' => 'P',
            'family_relation_code' => 'kepala_keluarga',
            'religion_code' => 'islam',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }
}
