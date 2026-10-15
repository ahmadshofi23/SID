<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Support\NameNormalizer;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Pelacakan status pengajuan oleh warga — FR-SRT-13, BR-11, KEP-014.
 *
 * Yang dijaga kelas ini bukan hanya "halamannya jalan", tetapi dua hal yang
 * membuat halaman publik ini boleh ada sama sekali: faktor kedua benar-benar
 * ditegakkan, dan tidak ada jawaban yang membedakan tiket yang terdaftar
 * dari yang tidak.
 */
final class LetterTrackingTest extends TestCase
{
    use DatabaseTransactions;

    private const BIRTH_DATE = '1985-03-17';

    private Region $rt;

    private Family $family;

    private LetterType $sktm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000009301');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Lacak 1',
        ])->save();

        $this->sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();
    }

    /* ===================== Jalur berhasil ===================== */

    #[Test]
    #[TestDox('Formulir pelacakan dapat dibuka tanpa login')]
    public function formulir_terbuka_tanpa_login(): void
    {
        $this->get('/surat/lacak')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Publik/LacakSurat')
                ->where('submitted', false)
                ->where('found', false));
    }

    #[Test]
    #[TestDox('Tiket + tanggal lahir yang benar menampilkan riwayat waktu seluruh tahap')]
    public function pelacakan_menampilkan_riwayat_lengkap(): void
    {
        $request = $this->issuedRequest('3201010101800401', 'Siti Aminah Rahayu');

        $response = $this->post('/surat/lacak', [
            'ticket_number' => $request->ticket_number,
            'birth_date' => self::BIRTH_DATE,
        ]);

        $response->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Publik/LacakSurat')
            ->where('found', true)
            ->where('tracking.ticket_number', $request->ticket_number)
            ->where('tracking.status', LetterRequest::STATUS_ISSUED)
            ->where('tracking.status_label', 'Diterbitkan')
            // Nama tersamar, sama seperti halaman verifikasi keaslian surat.
            ->where('tracking.resident_name', 'Siti A***** R*****')
            ->has('tracking.timeline', 3));

        $timeline = $response->viewData('page')['props']['tracking']['timeline'];

        $this->assertSame(
            [
                LetterRequest::STATUS_SUBMITTED,
                LetterRequest::STATUS_VERIFIED,
                LetterRequest::STATUS_ISSUED,
            ],
            array_column($timeline, 'to_status'),
            'Riwayat harus urut dari pengajuan sampai penerbitan — bukan urutan acak '
            .'dan bukan terbalik: warga membaca ini sebagai perjalanan suratnya.'
        );

        $this->assertNotContains(LetterRequest::STATUS_DRAFT, array_column($timeline, 'to_status'),
            'Tahap draf tidak ditampilkan ke warga: pengajuan loket dibuat dan dikirim '
            .'pada detik yang sama, dan dua baris berwaktu identik terbaca seperti galat.');

        foreach ($timeline as $step) {
            $this->assertNotEmpty($step['at'],
                'Setiap tahap wajib punya waktu — FR-SRT-13 meminta riwayat WAKTU.');
            $this->assertArrayNotHasKey('actor_name', $step,
                'Nama petugas pemeriksa tidak boleh ikut ke halaman publik.');
        }

        $props = $response->viewData('page')['props'];
        $this->assertNotNull($props['tracking']['letter_number'],
            'Nomor surat terbit perlu tampil agar warga dapat menyebutnya di kantor desa.');
    }

    #[Test]
    #[TestDox('Alasan penolakan tampil pada pelacakan — BR-11 menyebutnya eksplisit')]
    public function alasan_penolakan_tampil(): void
    {
        $resident = $this->makeResident('3201010101800402', 'Pemohon Ditolak');
        $request = $this->makeRequest($resident);

        $this->actingAs($this->userWithRole(Role::KASI_KESEJAHTERAAN))
            ->post("/surat/{$request->id}/tolak", [
                'reason' => 'Surat keterangan penghasilan dari tempat kerja belum dilampirkan.',
            ])
            ->assertRedirect();

        $response = $this->post('/surat/lacak', [
            'ticket_number' => $request->ticket_number,
            'birth_date' => self::BIRTH_DATE,
        ]);

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('found', true)
            ->where('tracking.status', LetterRequest::STATUS_REJECTED)
            ->where(
                'tracking.rejection_reason',
                'Surat keterangan penghasilan dari tempat kerja belum dilampirkan.'
            ));
    }

    /* ===================== Yang tidak boleh bocor ===================== */

    #[Test]
    #[TestDox('Tiket yang tidak ada dan tanggal lahir yang salah memberi jawaban yang SAMA')]
    public function kegagalan_tidak_membocorkan_keberadaan_tiket(): void
    {
        $request = $this->issuedRequest('3201010101800403', 'Pemohon Nyata');

        // (a) tiket tidak terdaftar
        $tidakAda = $this->post('/surat/lacak', [
            'ticket_number' => 'T261015-ZZZZZZ',
            'birth_date' => self::BIRTH_DATE,
        ]);

        // (b) tiket terdaftar, tetapi faktor kedua salah
        $faktorSalah = $this->post('/surat/lacak', [
            'ticket_number' => $request->ticket_number,
            'birth_date' => '1990-12-01',
        ]);

        foreach ([$tidakAda, $faktorSalah] as $response) {
            $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Publik/LacakSurat')
                ->where('submitted', true)
                ->where('found', false)
                ->missing('tracking'));
        }

        /*
         * Pembandingan kunci props, bukan sekadar `found === false`.
         *
         * Perbedaan sekecil kunci tambahan pada salah satu jawaban sudah
         * cukup untuk memastikan sebuah nomor tiket memang terdaftar —
         * itulah yang membuat nomor tiket layak ditebak.
         */
        $this->assertSame(
            array_keys($tidakAda->viewData('page')['props']),
            array_keys($faktorSalah->viewData('page')['props']),
            'Jawaban untuk tiket tak terdaftar dan faktor kedua salah harus '
            .'berbentuk sama.'
        );

        $serialized = json_encode($faktorSalah->viewData('page')['props']) ?: '';

        $this->assertStringNotContainsString('Pemohon Nyata', $serialized,
            'Tanggal lahir salah tidak boleh tetap mengirim data pengajuan.');
    }

    #[Test]
    #[TestDox('Percobaan yang gagal dicatat ke audit log, tanpa menyimpan tanggal lahir yang dicoba')]
    public function percobaan_gagal_dicatat(): void
    {
        $this->post('/surat/lacak', [
            'ticket_number' => 'T261015-ZZZZZZ',
            'birth_date' => '1990-12-01',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'letter.tracking_failed']);

        $log = AuditLog::query()
            ->where('action', 'letter.tracking_failed')
            ->latest('created_at')
            ->firstOrFail();

        $this->assertStringNotContainsString('1990-12-01', json_encode($log->context) ?: '',
            'Tanggal lahir yang dicoba adalah data pribadi; audit log bukan tempatnya menumpuk.');
    }

    #[Test]
    #[TestDox('NIK lengkap dan isi formulir tidak pernah sampai ke peramban')]
    public function data_pribadi_tidak_terkirim(): void
    {
        $request = $this->issuedRequest('3201010101800404', 'Siti Aminah Rahayu');

        $response = $this->post('/surat/lacak', [
            'ticket_number' => $request->ticket_number,
            'birth_date' => self::BIRTH_DATE,
        ]);

        $props = $response->viewData('page')['props'];
        $serialized = json_encode($props, JSON_UNESCAPED_SLASHES) ?: '';

        $this->assertStringNotContainsString('3201010101800404', $serialized,
            'NIK lengkap bocor di halaman pelacakan.');
        $this->assertStringNotContainsString('3201**', $serialized,
            'NIK tersamar pun tidak perlu ada di halaman publik.');
        $this->assertStringNotContainsString('Pengajuan beasiswa', $serialized,
            'Isi formulir pengajuan bocor di halaman pelacakan.');
        $this->assertStringNotContainsString('Siti Aminah Rahayu', $serialized,
            'Nama lengkap tidak boleh tampil penuh di halaman publik.');
        $this->assertStringNotContainsString('Jl. Lacak 1', $serialized,
            'Alamat tidak boleh ikut ke halaman pelacakan.');
        $this->assertStringNotContainsString('keluarga yang tidak mampu', $serialized,
            'Isi surat bocor di halaman pelacakan.');
        $this->assertArrayNotHasKey('form_data', $props['tracking']);
        $this->assertArrayNotHasKey('resident', $props['tracking'],
            'Objek penduduk tidak boleh dikirim apa adanya — hanya nama tersamar.');
    }

    /* ===================== Pagar laju & validasi ===================== */

    #[Test]
    #[TestDox('Pelacakan dibatasi laju: percobaan ke-9 dalam satu menit ditolak 429')]
    public function pembatasan_laju_bekerja(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->post('/surat/lacak', [
                'ticket_number' => 'T261015-ZZZZZ'.$i,
                'birth_date' => '1990-12-01',
            ])->assertOk();
        }

        $this->post('/surat/lacak', [
            'ticket_number' => 'T261015-ZZZZZ9',
            'birth_date' => '1990-12-01',
        ])->assertStatus(429);
    }

    #[Test]
    #[TestDox('Nomor tiket tanpa tanggal lahir ditolak — faktor kedua tidak opsional')]
    public function faktor_kedua_wajib(): void
    {
        $request = $this->issuedRequest('3201010101800405', 'Pemohon Tanpa Tanggal');

        $response = $this->post('/surat/lacak', [
            'ticket_number' => $request->ticket_number,
        ]);

        $response->assertSessionHasErrors('birth_date');
        $this->assertSame('Tanggal lahir pemohon wajib diisi.',
            $this->errorMessage($response, 'birth_date'));
    }

    #[Test]
    #[TestDox('Nomor tiket diterima dalam huruf kecil — warga mengetik ulang dari tanda terima')]
    public function tiket_huruf_kecil_diterima(): void
    {
        $request = $this->issuedRequest('3201010101800406', 'Pemohon Huruf Kecil');

        $this->post('/surat/lacak', [
            'ticket_number' => ' '.strtolower($request->ticket_number).' ',
            'birth_date' => self::BIRTH_DATE,
        ])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('found', true));
    }

    /* ===================== Pembantu ===================== */

    private function makeResident(string $nik, string $name): Resident
    {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $this->family->id,
            'region_id' => $this->rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_place' => 'Sukamaju',
            'birth_date' => self::BIRTH_DATE,
            'gender' => 'P',
            'religion_code' => 'islam',
            'marital_status_code' => 'belum_kawin',
            'occupation_code' => 'petani',
            'family_relation_code' => 'anak',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }

    /**
     * Pengajuan loket yang riwayatnya dibuat oleh alur sebenarnya.
     *
     * Barisnya TIDAK dibuat langsung dengan log palsu: yang diuji di sini
     * adalah riwayat yang benar-benar ditulis LetterRequestService, bukan
     * riwayat yang disusun oleh ujinya sendiri.
     */
    private function makeRequest(Resident $resident): LetterRequest
    {
        $operator = $this->userWithRole(Role::KASI_PEMERINTAHAN);

        $this->actingAs($operator)->post('/surat', [
            'letter_type_id' => $this->sktm->id,
            'resident_id' => $resident->id,
            'form' => [
                'keperluan' => 'Pengajuan beasiswa',
                'keterangan_tambahan' => 'Anak bernama Budi, kelas 10 SMAN 1.',
            ],
        ])->assertRedirect();

        // Sesi petugas dibuang: pelacakan harus diuji sebagai pengunjung anonim.
        $this->flushSession();
        auth()->logout();

        return LetterRequest::query()->where('resident_id', $resident->id)->firstOrFail();
    }

    private function issuedRequest(string $nik, string $name): LetterRequest
    {
        $resident = $this->makeResident($nik, $name);
        $request = $this->makeRequest($resident);

        $this->actingAs($this->userWithRole(Role::KASI_KESEJAHTERAAN))
            ->post("/surat/{$request->id}/verifikasi")->assertRedirect();

        $this->actingAs($this->userWithRole(Role::KEPALA_DESA))
            ->post("/surat/{$request->id}/terbitkan")->assertRedirect();

        $this->flushSession();
        auth()->logout();

        return $request->fresh() ?? $request;
    }
}
