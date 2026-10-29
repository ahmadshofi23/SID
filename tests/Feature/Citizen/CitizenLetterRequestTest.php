<?php

declare(strict_types=1);

namespace Tests\Feature\Citizen;

use App\Models\Family;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Support\NameNormalizer;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Portal warga & pengajuan surat daring — UC-05, FR-SRT-04, BR-10, RULE-11.
 *
 * Tiga hal yang dijaga kelas ini di luar "formulirnya jalan":
 *   1. Warga hanya melihat dan mengajukan untuk dirinya + satu KK, dan
 *      pembatasan itu datang dari lapis repository — bukan dari pemeriksaan
 *      tangan di controller, yang bisa terlupa di layar berikutnya.
 *   2. Syarat wajib benar-benar wajib di jalur daring (BR-10), berbeda dari
 *      loket yang membolehkan berkas menyusul.
 *   3. Jalur loket dan halaman pelacakan publik TIDAK tergantikan (KEP-014 §5).
 */
final class CitizenLetterRequestTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rt;

    private Family $family;

    private Resident $saya;

    private Resident $anak;

    private User $warga;

    private LetterType $domisili;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);
        Storage::fake('local');

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = $this->makeFamily('3201010000009901');
        $this->saya = $this->makeResident('3201011007400011', 'Suprapto Warga', $this->family);
        $this->anak = $this->makeResident('3201011007400012', 'Rina Warga', $this->family, 'anak');

        $this->warga = $this->makeCitizenAccount($this->saya);
        $this->domisili = LetterType::query()->where('code', 'SKD')->firstOrFail();
    }

    /* ============================ Pintu masuk ============================ */

    #[Test]
    #[TestDox('Portal warga tertutup bagi tamu, petugas, dan akun yang belum tertaut penduduk')]
    public function pintu_portal_warga_dijaga(): void
    {
        $this->get('/warga')->assertRedirect('/masuk');

        // Petugas diarahkan ke dasbornya, bukan ditolak: ini tautan salah klik.
        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->get('/warga')->assertRedirect('/dashboard');

        $lepas = $this->makeCitizenAccount($this->saya, linked: false);
        $this->actingAs($lepas)->get('/warga')->assertRedirect('/warga/belum-aktif');

        $this->actingAs($this->warga)->get('/warga')->assertOk();
    }

    #[Test]
    #[TestDox('Akun warga yang dinonaktifkan di tengah sesi langsung dikeluarkan')]
    public function akun_dinonaktifkan_di_tengah_sesi(): void
    {
        $this->actingAs($this->warga)->get('/warga')->assertOk();

        $this->warga->forceFill(['status' => User::STATUS_DISABLED])->save();

        /*
         * LoginController menjaga pintu masuk, tetapi sesi yang SUDAH hidup
         * tidak diperiksanya lagi. Yang menutup celah itu adalah
         * EnforceSessionPolicy, yang mengeluarkan akun non-aktif pada
         * permintaan berikutnya — jadi warga yang baru saja ditolak petugas
         * tidak dapat terus mengajukan surat sampai sesinya kedaluwarsa.
         *
         * Diuji di sini, bukan hanya di uji otentikasi, karena portal warga
         * adalah satu-satunya tempat akun berstatus itu benar-benar pernah
         * memegang sesi hidup.
         */
        $this->actingAs($this->warga->fresh())
            ->get('/warga')->assertRedirect('/masuk');
    }

    /* ============================== Lingkup ============================== */

    #[Test]
    #[TestDox('RULE-11: warga hanya dapat memilih pemohon dari satu Kartu Keluarga')]
    public function pemohon_terbatas_satu_kk(): void
    {
        $keluargaLain = $this->makeFamily('3201010000009902');
        $orangLain = $this->makeResident('3201011007400013', 'Tetangga Jauh', $keluargaLain);

        $response = $this->actingAs($this->warga)->get('/warga/ajukan')->assertOk();
        $body = (string) $response->getContent();

        $this->assertStringContainsString('Suprapto Warga', $body);
        $this->assertStringContainsString('Rina Warga', $body);
        $this->assertStringNotContainsString('Tetangga Jauh', $body,
            'Daftar pemohon memuat orang di luar Kartu Keluarga.');

        /*
         * Dan tidak cukup menyembunyikannya dari layar: permintaan buatan
         * tangan pun harus gagal, karena LetterRequestService mencari
         * pemohonnya lewat repository berlingkup yang sama.
         */
        $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payloadDenganSyarat(['resident_id' => $orangLain->id]))
            ->assertNotFound();

        $this->assertSame(0, LetterRequest::query()->count());
    }

    #[Test]
    #[TestDox('Pengajuan keluarga lain menghasilkan 404, bukan 403')]
    public function pengajuan_keluarga_lain_404(): void
    {
        $keluargaLain = $this->makeFamily('3201010000009903');
        $orangLain = $this->makeResident('3201011007400014', 'Warga Lain', $keluargaLain);

        $milikOrangLain = $this->buatPengajuanUntuk($orangLain);

        $this->actingAs($this->warga)
            ->get('/warga/pengajuan/'.$milikOrangLain->id)
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Pengajuan loket atas nama anggota keluarga tetap muncul di daftar warga')]
    public function pengajuan_loket_ikut_terlihat(): void
    {
        $loket = $this->buatPengajuanUntuk($this->anak, channel: 'walk_in');

        $this->actingAs($this->warga)
            ->get('/warga/pengajuan')
            ->assertOk()
            ->assertSee($loket->ticket_number, false);
    }

    /* ========================== Pengajuan daring ========================== */

    #[Test]
    #[TestDox('UC-05: pengajuan daring tersimpan dengan kanal online dan nomor tiket')]
    public function pengajuan_daring_berhasil(): void
    {
        $response = $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payloadDenganSyarat());

        $response->assertRedirect();

        $request = LetterRequest::query()->firstOrFail();

        $this->assertSame('online', $request->channel);
        $this->assertSame($this->saya->id, $request->resident_id);
        $this->assertSame($this->warga->id, $request->requested_by);
        $this->assertSame(LetterRequest::STATUS_SUBMITTED, $request->status);
        $this->assertNotEmpty($request->ticket_number);

        $this->actingAs($this->warga)
            ->get('/warga/pengajuan/'.$request->id)
            ->assertOk()
            ->assertSee($request->ticket_number, false);
    }

    #[Test]
    #[TestDox('BR-10: syarat wajib yang belum diunggah ditolak dan DISEBUTKAN satu per satu')]
    public function syarat_wajib_disebutkan_satu_per_satu(): void
    {
        $response = $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payload());

        $wajib = [];

        foreach ($this->domisili->requirements as $i => $syarat) {
            if (($syarat['required'] ?? true) === true) {
                $wajib[] = 'syarat.'.$i;
            }
        }

        $this->assertNotSame([], $wajib, 'Jenis surat uji tidak punya syarat wajib.');
        $response->assertSessionHasErrors($wajib);

        // Pesannya menyebut NAMA dokumennya, bukan "lengkapi syarat".
        $this->assertStringContainsString(
            (string) ($this->domisili->requirements[0]['label'] ?? ''),
            $this->errorMessage($response, $wajib[0])
        );

        $this->assertSame(0, LetterRequest::query()->count(),
            'Pengajuan tersimpan walau syarat wajibnya kurang.');
    }

    #[Test]
    #[TestDox('Di loket syarat boleh menyusul — perbedaannya disengaja, bukan kelalaian')]
    public function loket_tetap_membolehkan_syarat_menyusul(): void
    {
        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post('/surat', [
                'letter_type_id' => $this->domisili->id,
                'resident_id' => $this->saya->id,
                'purpose' => 'Keperluan uji',
                'form' => ['keperluan' => 'Keperluan uji'],
            ])
            ->assertRedirect();

        $this->assertSame(1, LetterRequest::query()->count());
    }

    #[Test]
    #[TestDox('UC-05 7b: pengajuan sejenis yang berjalan memperingatkan, dan lolos setelah dikonfirmasi')]
    public function pengajuan_kembar_diperingatkan_bukan_diblokir(): void
    {
        $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payloadDenganSyarat())
            ->assertRedirect();

        $ditolak = $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payloadDenganSyarat());

        $ditolak->assertSessionHasErrors('resident_id');
        $this->assertSame(1, LetterRequest::query()->count());

        $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payloadDenganSyarat(['konfirmasi_kembar' => '1']))
            ->assertRedirect();

        $this->assertSame(2, LetterRequest::query()->count(),
            'Konfirmasi warga tidak dihormati — alasan sah untuk dua surat sejenis memang ada.');
    }

    #[Test]
    #[TestDox('UC-05 7c: pemohon berstatus pindah atau meninggal ditolak')]
    public function pemohon_tidak_aktif_ditolak(): void
    {
        $this->anak->forceFill(['status' => 'pindah_keluar'])->save();

        $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payloadDenganSyarat(['resident_id' => $this->anak->id]))
            ->assertSessionHasErrors();

        $this->assertSame(0, LetterRequest::query()->count());
    }

    #[Test]
    #[TestDox('Jenis surat yang tidak dibuka daring tidak dapat diajukan dari portal warga')]
    public function jenis_bukan_publik_ditolak(): void
    {
        $this->domisili->forceFill(['is_public' => false])->save();

        $this->actingAs($this->warga)->get('/warga/ajukan')
            ->assertOk()
            ->assertDontSee($this->domisili->name, false);

        $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payloadDenganSyarat())
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Identitas pemohon tidak diambil dari permintaan: nama palsu diabaikan')]
    public function identitas_tidak_dapat_dipalsukan(): void
    {
        $this->actingAs($this->warga)
            ->post('/warga/ajukan', $this->payloadDenganSyarat([
                'full_name' => 'Nama Palsu',
                'nik' => '9999999999999999',
            ]))
            ->assertRedirect();

        $request = LetterRequest::query()->with('resident')->firstOrFail();

        /*
         * Identitas tercetak di surat berasal dari data penduduk, bukan dari
         * apa yang dikirim peramban (UC-05 langkah 3). Kolom tambahan pada
         * permintaan tidak punya jalan masuk ke mana pun.
         */
        $this->assertSame('Suprapto Warga', $request->resident->full_name);
        $this->assertArrayNotHasKey('full_name', $request->form_data);
        $this->assertArrayNotHasKey('nik', $request->form_data);
    }

    /* ======================= Jalur lama tetap hidup ======================= */

    #[Test]
    #[TestDox('KEP-014 §5: pelacakan publik tanpa login tetap hidup setelah akun warga ada')]
    public function pelacakan_publik_tetap_hidup(): void
    {
        $this->get('/surat/lacak')->assertOk();

        $request = $this->buatPengajuanUntuk($this->saya, channel: 'walk_in');

        $this->post('/surat/lacak', [
            'ticket_number' => $request->ticket_number,
            'birth_date' => '1985-06-15',
        ])->assertOk();
    }

    /* ================================ Bantu =============================== */

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return [
            'letter_type_id' => $this->domisili->id,
            'resident_id' => $this->saya->id,
            'purpose' => 'Keperluan uji',
            'form' => ['keperluan' => 'Keperluan uji'],
            ...$override,
        ];
    }

    /** @param array<string, mixed> $override */
    private function payloadDenganSyarat(array $override = []): array
    {
        $berkas = [];

        foreach ($this->domisili->requirements as $i => $syarat) {
            if (($syarat['required'] ?? true) === true) {
                $berkas[$i] = UploadedFile::fake()
                    ->createWithContent('syarat'.$i.'.jpg', "\xFF\xD8\xFF".str_repeat('a', 64));
            }
        }

        return [...$this->payload($override), 'syarat' => $berkas];
    }

    private function buatPengajuanUntuk(Resident $resident, string $channel = 'online'): LetterRequest
    {
        $request = new LetterRequest;
        $request->forceFill([
            'letter_type_id' => $this->domisili->id,
            'ticket_number' => 'T'.now()->format('ymd').'-'.strtoupper(substr(uniqid(), -6)),
            'resident_id' => $resident->id,
            'channel' => $channel,
            'purpose' => 'Uji',
            'form_data' => ['keperluan' => 'Uji'],
            'status' => LetterRequest::STATUS_SUBMITTED,
            'current_step' => 1,
            'submitted_at' => now(),
            'due_at' => now()->addDay(),
        ])->save();

        return $request;
    }

    private function makeFamily(string $kk): Family
    {
        $family = new Family;
        $family->setKkNumber($kk);
        $family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Warga '.substr($kk, -2),
        ])->save();

        return $family;
    }

    private function makeResident(
        string $nik,
        string $name,
        Family $family,
        string $relation = 'kepala_keluarga',
    ): Resident {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $this->rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1985-06-15',
            'gender' => 'L',
            'family_relation_code' => $relation,
            'religion_code' => 'islam',
            'citizenship' => 'WNI',
            'marital_status_code' => 'kawin',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }

    private function makeCitizenAccount(Resident $resident, bool $linked = true): User
    {
        return $this->userWithRole(
            Role::WARGA,
            residentId: $linked ? $resident->id : null,
        );
    }
}
