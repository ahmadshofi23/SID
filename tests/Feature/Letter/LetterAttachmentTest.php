<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Models\Family;
use App\Models\LetterAttachment;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Services\Pdf\FakePdfRenderer;
use App\Services\Pdf\PdfRenderer;
use App\Support\NameNormalizer;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Lampiran syarat pengajuan surat — Sprint 5, KEP-013 §1, NFR-SEC-05.
 */
final class LetterAttachmentTest extends TestCase
{
    use DatabaseTransactions;

    private Region $dusunKrajan;

    private Region $rt;

    private Family $family;

    private LetterType $sktm;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $this->app->instance(PdfRenderer::class, new FakePdfRenderer);

        $this->disk = (string) config('sid.uploads.disk', 'local');
        Storage::fake($this->disk);

        $this->dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $this->dusunKrajan->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000009601');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Lampiran 1',
        ])->save();

        $this->sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();
    }

    /* ===================== Validasi isi berkas ===================== */

    #[Test]
    #[TestDox('Berkas .pdf yang isinya HTML DITOLAK — ekstensi tidak membuktikan apa pun')]
    public function pdf_palsu_berisi_html_ditolak(): void
    {
        $request = $this->makeRequest('3201010101800501', 'Pemohon Palsu');

        $palsu = UploadedFile::fake()->createWithContent(
            'surat-pengantar-rt.pdf',
            '<!doctype html><html><body><script>fetch("/")</script></body></html>',
        );

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->from("/surat/{$request->id}")
            ->post("/surat/{$request->id}/lampiran", ['berkas' => $palsu]);

        $response->assertRedirect("/surat/{$request->id}");

        $this->assertStringContainsString(
            'bukan JPG, PNG, atau PDF',
            $this->errorMessage($response, 'berkas'),
        );

        $this->assertSame(0, LetterAttachment::query()->where('request_id', $request->id)->count());
        $this->assertCount(0, Storage::disk($this->disk)->allFiles());
    }

    #[Test]
    #[TestDox('Berkas JPEG yang dinamai .pdf ditolak walau isinya sah')]
    public function isi_sah_tetapi_nama_berdusta_ditolak(): void
    {
        $request = $this->makeRequest('3201010101800502', 'Pemohon Salah Nama');

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->from("/surat/{$request->id}")
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()->createWithContent('ktp.pdf', $this->jpegBytes()),
            ]);

        $this->assertStringContainsString(
            'isinya JPG',
            $this->errorMessage($response, 'berkas'),
        );

        $this->assertSame(0, LetterAttachment::query()->where('request_id', $request->id)->count());
    }

    #[Test]
    #[TestDox('Berkas di atas 5 MB ditolak dengan ukuran yang disebutkan')]
    public function berkas_melebihi_batas_ditolak(): void
    {
        $request = $this->makeRequest('3201010101800503', 'Pemohon Besar');

        $maxKb = (int) (config('sid.uploads.max_bytes') / 1024);

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->from("/surat/{$request->id}")
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()
                    ->create('pindaian-besar.pdf', $maxKb + 64, 'application/pdf'),
            ]);

        $this->assertStringContainsString('melebihi batas', $this->errorMessage($response, 'berkas'));
        $this->assertStringContainsString('5,0 MB', $this->errorMessage($response, 'berkas'));

        $this->assertSame(0, LetterAttachment::query()->where('request_id', $request->id)->count());
    }

    #[Test]
    #[TestDox('Berkas tepat di bawah batas tetap diterima')]
    public function berkas_di_bawah_batas_diterima(): void
    {
        $request = $this->makeRequest('3201010101800504', 'Pemohon Pas');

        $maxBytes = (int) config('sid.uploads.max_bytes');
        $isi = '%PDF-1.7'.str_repeat('x', $maxBytes - 16);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()->createWithContent('besar-tapi-sah.pdf', $isi),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LetterAttachment::query()->where('request_id', $request->id)->count());
    }

    /* ===================== Penyimpanan ===================== */

    #[Test]
    #[TestDox('Berkas disimpan dengan nama ACAK di disk privat, bukan nama asli dari peramban')]
    public function nama_berkas_diacak_ulang(): void
    {
        $request = $this->makeRequest('3201010101800505', 'Pemohon Nama');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()
                    ->createWithContent('../../KTP Budi Santoso.pdf', '%PDF-1.4 isi'),
                'requirement_index' => 0,
            ])
            ->assertSessionHasNoErrors();

        /** @var LetterAttachment $attachment */
        $attachment = LetterAttachment::query()->where('request_id', $request->id)->firstOrFail();

        Storage::disk($this->disk)->assertExists($attachment->path);

        $this->assertStringStartsWith('lampiran-pengajuan/'.$request->id.'/', $attachment->path);
        $this->assertStringEndsWith('.pdf', $attachment->path);

        // Nama asli TIDAK boleh menyentuh sistem berkas (NFR-SEC-05).
        $this->assertStringNotContainsString('Budi', $attachment->path);
        $this->assertStringNotContainsString('..', $attachment->path);
        $this->assertStringNotContainsString(' ', $attachment->path);

        // Ia tetap disimpan di basis data, untuk ditampilkan dan diunduh.
        $this->assertStringContainsString('KTP Budi Santoso', $attachment->original_name);

        // Tipe & sidik jari diambil dari ISI berkas.
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertSame(hash('sha256', '%PDF-1.4 isi'), $attachment->sha256);

        // Label syarat DISALIN saat unggah — docs/13 §2, lihat migrasinya.
        $this->assertSame(
            $this->sktm->requirements[0]['label'],
            $attachment->requirement_label,
        );
    }

    #[Test]
    #[TestDox('Indeks syarat yang datang sebagai STRING tetap diterima (multipart)')]
    public function indeks_syarat_berupa_string_diterima(): void
    {
        $request = $this->makeRequest('3201010101800520', 'Pemohon Multipart');

        /*
         * Unggahan sungguhan dari peramban dikirim multipart/form-data, dan
         * di sana setiap nilai sampai sebagai STRING — "2", bukan 2. Uji
         * yang menyerahkan array PHP biasa mengirim int dan tidak akan
         * pernah menyentuh jalur ini; versi pertama kode ini lolos seluruh
         * rangkaian uji lalu menghasilkan HTTP 500 pada unggahan pertama
         * yang dicoba di peramban.
         */
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()->createWithContent('rt.pdf', '%PDF-1.4 rt'),
                'requirement_index' => '2',
            ])
            ->assertSessionHasNoErrors();

        $attachment = LetterAttachment::query()->where('request_id', $request->id)->firstOrFail();

        $this->assertSame(2, $attachment->requirement_index);
        $this->assertSame($this->sktm->requirements[2]['label'], $attachment->requirement_label);
    }

    #[Test]
    #[TestDox('Label syarat yang tersalin tidak ikut berubah saat jenis surat disunting')]
    public function label_syarat_tidak_ikut_berubah(): void
    {
        $request = $this->makeRequest('3201010101800506', 'Pemohon Salin');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()->createWithContent('kk.pdf', '%PDF-1.4 kk'),
                'requirement_index' => 0,
            ])
            ->assertSessionHasNoErrors();

        $labelSaatUnggah = (string) LetterAttachment::query()
            ->where('request_id', $request->id)->value('requirement_label');

        $this->sktm->forceFill(['requirements' => [
            ['label' => 'SYARAT BARU YANG DISISIPKAN DI DEPAN', 'type' => 'file', 'required' => true],
            ...$this->sktm->requirements,
        ]])->save();

        $this->assertSame($labelSaatUnggah, LetterAttachment::query()
            ->where('request_id', $request->id)->value('requirement_label'));
    }

    #[Test]
    #[TestDox('Berkas syarat dapat diunggah sekalian saat pengajuan dibuat di loket')]
    public function berkas_ikut_saat_pengajuan_dibuat(): void
    {
        $resident = $this->makeResident('3201010101800507', 'Pemohon Loket');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post('/surat', [
                'letter_type_id' => $this->sktm->id,
                'resident_id' => $resident->id,
                'purpose' => 'Pengajuan beasiswa',
                'form' => ['keperluan' => 'Pengajuan beasiswa'],
                'syarat' => [
                    0 => UploadedFile::fake()->createWithContent('ktp.jpg', $this->jpegBytes()),
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $request = LetterRequest::query()->where('resident_id', $resident->id)->firstOrFail();

        $this->assertSame(1, LetterAttachment::query()->where('request_id', $request->id)->count());
    }

    #[Test]
    #[TestDox('Berkas yang ditolak saat pembuatan tidak meninggalkan pengajuan setengah jadi')]
    public function penolakan_saat_pembuatan_tidak_menyisakan_pengajuan(): void
    {
        $resident = $this->makeResident('3201010101800508', 'Pemohon Gagal Buat');

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->from('/surat/ajukan')
            ->post('/surat', [
                'letter_type_id' => $this->sktm->id,
                'resident_id' => $resident->id,
                'form' => ['keperluan' => 'Pengajuan beasiswa'],
                'syarat' => [
                    0 => UploadedFile::fake()->createWithContent('palsu.pdf', '<html>bukan pdf</html>'),
                ],
            ]);

        $response->assertRedirect('/surat/ajukan');
        $this->assertNotSame('', $this->errorMessage($response, 'syarat.0'));

        /*
         * Tidak boleh ada tiket baru di antrean. Pengajuan yang terlanjur
         * dibuat lalu gagal di lampirannya membuat petugas melihat pesan
         * galat DAN tiket baru sekaligus — lalu mengulang dari awal dan
         * menghasilkan pengajuan ganda.
         */
        $this->assertSame(0, LetterRequest::query()->where('resident_id', $resident->id)->count());
    }

    /* ===================== Otorisasi unduhan ===================== */

    #[Test]
    #[TestDox('Petugas dalam lingkup dapat membuka berkas lampiran')]
    public function unduhan_dalam_lingkup_berhasil(): void
    {
        [$request, $attachment] = $this->makeRequestWithAttachment('3201010101800509', 'Pemohon Unduh');

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/{$request->id}/lampiran/{$attachment->id}");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('x-content-type-options', 'nosniff');

        $this->assertDatabaseHas('audit_logs', ['action' => 'letter_request.attachment_viewed']);
    }

    #[Test]
    #[TestDox('Berkas lampiran di luar wilayah menghasilkan 404, BUKAN 403 (docs/06 §4)')]
    public function unduhan_lintas_wilayah_menghasilkan_404(): void
    {
        [$request, $attachment] = $this->makeRequestWithAttachment('3201010101800510', 'Pemohon Luar');

        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        /*
         * Kepala Dusun Sidomulyo PUNYA izin surat.lihat; yang diuji di sini
         * lingkup wilayahnya. 403 akan memastikan bagi penyerang bahwa
         * lampiran dengan id itu memang ada.
         */
        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $dusunLain))
            ->get("/surat/{$request->id}/lampiran/{$attachment->id}")
            ->assertNotFound();

        // Kepala dusun yang benar tetap dapat membukanya.
        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan))
            ->get("/surat/{$request->id}/lampiran/{$attachment->id}")
            ->assertOk();
    }

    #[Test]
    #[TestDox('Menghapus lampiran milik pengajuan lain menghasilkan 404')]
    public function hapus_lintas_wilayah_menghasilkan_404(): void
    {
        [$request, $attachment] = $this->makeRequestWithAttachment('3201010101800511', 'Pemohon Hapus Luar');

        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        /*
         * Pengguna di sini sengaja dirakit dari DUA peran: Kepala Dusun
         * (satu-satunya peran yang dibatasi wilayah, Role::REGION_LIMITED_ROLES)
         * ditambah Operator (satu-satunya pemegang `surat.ubah` yang bukan
         * perangkat desa pusat). Gabungan ini nyata — kepala dusun yang juga
         * menjaga loket — dan ia satu-satunya cara mencapai pemeriksaan
         * LINGKUP pada penghapusan: peran berwilayah tanpa `surat.ubah`
         * berhenti lebih awal di middleware izin dengan 403, dan uji itu
         * tidak akan mengatakan apa pun tentang pembatasan wilayah.
         */
        $this->actingAs($this->dusunOperator($dusunLain))
            ->delete("/surat/{$request->id}/lampiran/{$attachment->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('letter_attachments', ['id' => $attachment->id]);
        Storage::disk($this->disk)->assertExists($attachment->path);

        // Orang yang sama di dusun yang benar tetap dapat menghapusnya.
        $this->actingAs($this->dusunOperator($this->dusunKrajan))
            ->delete("/surat/{$request->id}/lampiran/{$attachment->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('letter_attachments', ['id' => $attachment->id]);
    }

    #[Test]
    #[TestDox('Id lampiran dari pengajuan lain tidak dapat diambil lewat id pengajuan yang sah')]
    public function lampiran_pengajuan_lain_menghasilkan_404(): void
    {
        [$milikSendiri] = $this->makeRequestWithAttachment('3201010101800512', 'Pemohon Satu');
        [, $milikOrangLain] = $this->makeRequestWithAttachment('3201010101800513', 'Pemohon Dua');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/{$milikSendiri->id}/lampiran/{$milikOrangLain->id}")
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Berkas yang hilang dari penyimpanan memberi pesan, bukan unduhan kosong')]
    public function berkas_hilang_memberi_pesan(): void
    {
        [$request, $attachment] = $this->makeRequestWithAttachment('3201010101800514', 'Pemohon Hilang');

        Storage::disk($this->disk)->delete($attachment->path);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/{$request->id}/lampiran/{$attachment->id}")
            ->assertStatus(410);
    }

    /* ===================== Penghapusan & arsip ===================== */

    #[Test]
    #[TestDox('Lampiran dapat dihapus selama pengajuan belum terbit')]
    public function penghapusan_sebelum_terbit_berhasil(): void
    {
        [$request, $attachment] = $this->makeRequestWithAttachment('3201010101800515', 'Pemohon Hapus');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->from("/surat/{$request->id}")
            ->delete("/surat/{$request->id}/lampiran/{$attachment->id}")
            ->assertRedirect("/surat/{$request->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('letter_attachments', ['id' => $attachment->id]);
        Storage::disk($this->disk)->assertMissing($attachment->path);

        $this->assertDatabaseHas('audit_logs', ['action' => 'letter_request.attachment_deleted']);
    }

    #[Test]
    #[TestDox('Setelah surat terbit, lampirannya TIDAK dapat dihapus (ADR-003)')]
    public function penghapusan_setelah_terbit_ditolak(): void
    {
        [$request, $attachment] = $this->makeRequestWithAttachment('3201010101800516', 'Pemohon Terbit');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect();

        $this->assertSame(LetterRequest::STATUS_ISSUED, $request->fresh()->status);

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->from("/surat/{$request->id}")
            ->delete("/surat/{$request->id}/lampiran/{$attachment->id}");

        $response->assertRedirect("/surat/{$request->id}");
        $this->assertStringContainsString(
            'Surat sudah terbit',
            $this->errorMessage($response, 'lampiran'),
        );

        /*
         * Berkasnya HARUS masih ada — baik barisnya maupun isinya. Lampiran
         * adalah dasar penerbitan surat; tanpa itu pertanyaan "atas dasar
         * apa surat ini terbit?" tidak lagi dapat dijawab saat pemeriksaan.
         */
        $this->assertDatabaseHas('letter_attachments', ['id' => $attachment->id]);
        Storage::disk($this->disk)->assertExists($attachment->path);
    }

    #[Test]
    #[TestDox('Setelah surat terbit, lampiran baru juga tidak dapat ditambahkan')]
    public function unggahan_setelah_terbit_ditolak(): void
    {
        [$request] = $this->makeRequestWithAttachment('3201010101800517', 'Pemohon Tambah');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect();

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->from("/surat/{$request->id}")
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()->createWithContent('susulan.pdf', '%PDF-1.4 susulan'),
            ]);

        $this->assertStringContainsString(
            'Surat sudah terbit',
            $this->errorMessage($response, 'lampiran'),
        );

        $this->assertSame(1, LetterAttachment::query()->where('request_id', $request->id)->count());
    }

    #[Test]
    #[TestDox('Tanpa izin surat.ubah, unggahan ditolak 403 — bukan 404')]
    public function unggahan_tanpa_izin_ditolak(): void
    {
        $request = $this->makeRequest('3201010101800518', 'Pemohon Tanpa Izin');

        $this->actingAs($this->userWithRole(Role::KAUR_KEUANGAN))
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 a'),
            ])
            ->assertForbidden();
    }

    /* ===================== Tampilan ===================== */

    #[Test]
    #[TestDox('Layar pemeriksaan menampilkan lampiran, termasuk yang hilang dari penyimpanan')]
    public function layar_menampilkan_lampiran_dan_yang_hilang(): void
    {
        [$request, $attachment] = $this->makeRequestWithAttachment('3201010101800519', 'Pemohon Layar');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/{$request->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Surat/Pengajuan/Detail')
                ->where('attachments.0.id', $attachment->id)
                ->where('attachments.0.tersedia', true)
                // Letak berkas di penyimpanan bukan urusan peramban.
                ->missing('attachments.0.path')
                ->where('upload.max_bytes', (int) config('sid.uploads.max_bytes')));

        Storage::disk($this->disk)->delete($attachment->path);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/{$request->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('attachments.0.tersedia', false));
    }

    /* ===================== Pembantu ===================== */

    /**
     * Kepala Dusun yang juga Operator: dibatasi wilayah DAN punya surat.ubah.
     *
     * Lihat alasan perakitannya di hapus_lintas_wilayah_menghasilkan_404().
     */
    private function dusunOperator(Region $region): User
    {
        $user = $this->userWithRole(Role::KEPALA_DUSUN, $region);

        $user->roles()->attach(
            Role::query()->where('code', Role::OPERATOR)->firstOrFail()->id,
            ['region_id' => $region->id],
        );

        return $user->fresh(['roles.permissions', 'resident']);
    }

    /** JPEG terkecil yang magic bytes-nya sah. */
    private function jpegBytes(): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01".str_repeat("\x00", 64)."\xFF\xD9";
    }

    /** @return array{0: LetterRequest, 1: LetterAttachment} */
    private function makeRequestWithAttachment(string $nik, string $name): array
    {
        $request = $this->makeRequest($nik, $name);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/lampiran", [
                'berkas' => UploadedFile::fake()
                    ->createWithContent('pengantar-rt.pdf', '%PDF-1.4 pengantar '.$nik),
                'requirement_index' => 0,
            ])
            ->assertSessionHasNoErrors();

        return [$request, LetterAttachment::query()
            ->where('request_id', $request->id)->firstOrFail()];
    }

    private function makeRequest(string $nik, string $name): LetterRequest
    {
        return LetterRequest::query()->create([
            'letter_type_id' => $this->sktm->id,
            'ticket_number' => 'T'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
            'resident_id' => $this->makeResident($nik, $name)->id,
            'channel' => 'walk_in',
            'purpose' => 'Pengajuan beasiswa',
            'form_data' => ['keperluan' => 'Pengajuan beasiswa'],
            'status' => LetterRequest::STATUS_VERIFIED,
            'current_step' => 1,
            'submitted_at' => now(),
            'due_at' => now()->addDay(),
        ]);
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
            'birth_place' => 'Sukamaju',
            'birth_date' => '1985-03-17',
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
}
