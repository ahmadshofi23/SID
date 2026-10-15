<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Jobs\RenderLetterPdf;
use App\Models\Family;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Models\VillageProfile;
use App\Services\Letter\LetterPdfService;
use App\Services\Pdf\FakePdfRenderer;
use App\Services\Pdf\PdfPaper;
use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PdfRenderException;
use App\Support\NameNormalizer;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Render & unduhan PDF surat — Sprint 5, KEP-013, ADR-003.
 */
final class LetterPdfTest extends TestCase
{
    use DatabaseTransactions;

    private Region $dusunKrajan;

    private Region $rt;

    private Family $family;

    private LetterType $sktm;

    private FakePdfRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $this->renderer = new FakePdfRenderer;
        $this->app->instance(PdfRenderer::class, $this->renderer);

        $this->dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $this->dusunKrajan->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000009501');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. PDF 1',
        ])->save();

        $this->sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();
    }

    /* ===================== Render dari snapshot ===================== */

    #[Test]
    #[TestDox('Penerbitan merender PDF dari snapshot dan menyimpannya di disk')]
    public function penerbitan_merender_dan_menyimpan_pdf(): void
    {
        $letter = $this->issueLetter('3201010101800401', 'Pemohon Pdf');

        $this->assertSame(Letter::PDF_READY, $letter->pdf_status);
        $this->assertNotNull($letter->pdf_path);
        $this->assertNull($letter->pdf_failed_reason);
        $this->assertNotNull($letter->pdf_rendered_at);

        $path = (string) $letter->pdf_path;

        Storage::disk('local')->assertExists($path);

        $stored = (string) Storage::disk('local')->get($path);

        $this->assertStringStartsWith('%PDF-', $stored);
        $this->assertSame(hash('sha256', $stored), $letter->pdf_hash,
            'Sidik jari berkas wajib cocok — ia yang membuktikan arsip tidak berubah (ADR-003).');

        // Dikelompokkan per tahun terbit, memakai UUID — bukan nomor surat,
        // yang memuat garis miring.
        $this->assertSame('surat/'.$letter->issued_year.'/'.$letter->id.'.pdf', $path);
    }

    #[Test]
    #[TestDox('PDF dirender dari letters.rendered_html, BUKAN dirakit ulang dari template (ADR-003)')]
    public function pdf_dirender_dari_snapshot_bukan_template_terbaru(): void
    {
        $letter = $this->issueLetter('3201010101800402', 'Pemohon Snapshot');

        $htmlSaatTerbit = (string) $this->renderer->lastHtml;

        $this->assertStringContainsString($letter->rendered_html, $htmlSaatTerbit,
            'Badan surat harus disalin apa adanya dari snapshot.');

        /*
         * Template jenis surat disunting SETELAH surat terbit — persis
         * keadaan yang dijaga ADR-003. Bila PDF dirakit ulang dari template,
         * surat lama akan berubah isinya saat berkasnya dibuat ulang; yang
         * dipegang warga dan yang ada di arsip menjadi dua dokumen berbeda.
         */
        $this->sktm->forceFill([
            'template_html' => 'ISI TEMPLATE BARU YANG TIDAK BOLEH MUNCUL DI SURAT LAMA.',
        ])->save();

        app(LetterPdfService::class)->render($letter->fresh());

        $htmlSesudah = (string) $this->renderer->lastHtml;

        $this->assertStringNotContainsString('TIDAK BOLEH MUNCUL', $htmlSesudah);
        $this->assertStringContainsString($letter->rendered_html, $htmlSesudah);
    }

    #[Test]
    #[TestDox('Dokumen PDF memuat kop desa, QR verifikasi tertanam, dan kertas F4')]
    public function dokumen_memuat_kop_qr_dan_kertas_f4(): void
    {
        $letter = $this->issueLetter('3201010101800403', 'Pemohon Kop');

        $html = (string) $this->renderer->lastHtml;

        $this->assertStringContainsString('size: 215mm 330mm', $html,
            'Ukuran kertas F4 ditetapkan di CSS @page — satu-satunya tempat kedua driver membacanya.');
        $village = VillageProfile::current();

        $this->assertStringContainsString('Pemerintah Kabupaten '.$village->regency, $html,
            'Kop desa harus ikut tercetak — PDF bukan hanya badan suratnya.');
        $this->assertStringContainsString('Desa '.$village->name, $html);
        $this->assertStringContainsString($letter->letter_number, $html);
        // Dikelompokkan empat-empat agar dapat diketik ulang dari kertas
        // ketika pemindai QR penerima surat tidak berfungsi.
        $this->assertStringContainsString(
            implode(' ', str_split($letter->verification_code, 4)), $html,
        );

        // QR ditanam sebagai SVG, bukan ditautkan ke layanan luar: Chromium
        // di produksi berjalan tanpa akses internet.
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('api.qrserver.com', $html);

        // Halaman PDF-nya sendiri berukuran F4.
        $paper = new PdfPaper(215, 330);
        $stored = (string) Storage::disk('local')->get((string) $letter->pdf_path);

        $this->assertStringContainsString(
            '/MediaBox [0 0 '.round($paper->widthPoints(), 2).' '.round($paper->heightPoints(), 2).']',
            $stored,
        );
    }

    /* ===================== Otorisasi unduhan ===================== */

    #[Test]
    #[TestDox('Petugas dalam lingkup dapat mengunduh PDF surat')]
    public function unduhan_dalam_lingkup_berhasil(): void
    {
        $letter = $this->issueLetter('3201010101800404', 'Pemohon Unduh');

        $response = $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/arsip/{$letter->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            $letter->pdfFileName(),
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString('-', $letter->pdfFileName());
        $this->assertStringNotContainsString('/', $letter->pdfFileName(),
            'Garis miring nomor surat harus hilang dari nama berkas.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'letter.pdf_downloaded']);
    }

    #[Test]
    #[TestDox('Surat di luar wilayah menghasilkan 404, BUKAN 403 (docs/06 §4)')]
    public function unduhan_di_luar_wilayah_menghasilkan_404(): void
    {
        $letter = $this->issueLetter('3201010101800405', 'Pemohon Luar');

        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        /*
         * Kepala Dusun Sidomulyo PUNYA izin surat.lihat, jadi yang diuji di
         * sini bukan izinnya melainkan lingkup wilayahnya. 403 akan memastikan
         * bagi penyerang bahwa surat dengan id itu ada; 404 tidak.
         */
        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $dusunLain))
            ->get("/surat/arsip/{$letter->id}/pdf")
            ->assertNotFound();

        // Kepala dusun yang benar tetap dapat mengunduh.
        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan))
            ->get("/surat/arsip/{$letter->id}/pdf")
            ->assertOk();
    }

    #[Test]
    #[TestDox('Tanpa izin surat.lihat, unduhan ditolak 403')]
    public function unduhan_tanpa_izin_ditolak(): void
    {
        $letter = $this->issueLetter('3201010101800406', 'Pemohon Tanpa Izin');

        $this->actingAs($this->userWithRole(Role::KAUR_KEUANGAN))
            ->get("/surat/arsip/{$letter->id}/pdf")
            ->assertForbidden();
    }

    /* ===================== Kegagalan render ===================== */

    #[Test]
    #[TestDox('Render yang gagal tercatat di barisnya, bukan menggantung di "menunggu"')]
    public function kegagalan_render_terlihat_petugas(): void
    {
        $letter = $this->issueLetter('3201010101800407', 'Pemohon Gagal');

        $this->bindFailingRenderer('Layanan perender PDF tidak dapat dihubungi di http://localhost:3000.');

        // Berkas lama dihapus supaya job tidak berhenti di pemeriksaan
        // "sudah ada PDF".
        $letter->forceFill([
            'pdf_status' => Letter::PDF_PENDING,
            'pdf_path' => null,
            'pdf_hash' => null,
        ])->save();

        $job = new RenderLetterPdf($letter->id);

        try {
            $job->handle(app(LetterPdfService::class));
            $this->fail('Perenderan yang gagal harus melempar pengecualian.');
        } catch (PdfRenderException $e) {
            $job->failed($e);
        }

        $letter->refresh();

        $this->assertSame(Letter::PDF_FAILED, $letter->pdf_status);
        $this->assertNotNull($letter->pdf_failed_reason);
        $this->assertStringContainsString('tidak dapat dihubungi', (string) $letter->pdf_failed_reason,
            'Pesannya harus dapat dibaca petugas, bukan jejak tumpukan.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'letter.pdf_failed']);

        // Surat tetap SAH dan tetap dapat dicetak dari layar — yang hilang
        // hanya berkas PDF-nya.
        $this->assertSame(Letter::STATUS_VALID, $letter->status);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/arsip/{$letter->id}/cetak")
            ->assertOk();
    }

    #[Test]
    #[TestDox('Unduhan saat PDF belum siap memberi pesan, bukan berkas rusak')]
    public function unduhan_saat_belum_siap_memberi_pesan(): void
    {
        $letter = $this->issueLetter('3201010101800408', 'Pemohon Belum Siap');

        $letter->forceFill([
            'pdf_status' => Letter::PDF_FAILED,
            'pdf_path' => null,
            'pdf_failed_reason' => 'Chrome tidak ditemukan.',
        ])->save();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/arsip/{$letter->id}/pdf")
            ->assertStatus(409);
    }

    #[Test]
    #[TestDox('Berkas yang hilang dari penyimpanan dikembalikan ke status gagal, bukan unduhan kosong')]
    public function berkas_hilang_dikembalikan_ke_gagal(): void
    {
        $letter = $this->issueLetter('3201010101800409', 'Pemohon Hilang');

        Storage::disk('local')->delete((string) $letter->pdf_path);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get("/surat/arsip/{$letter->id}/pdf")
            ->assertStatus(409);

        $this->assertSame(Letter::PDF_FAILED, $letter->fresh()->pdf_status);
    }

    #[Test]
    #[TestDox('Petugas dapat mengantrekan ulang perenderan yang gagal')]
    public function perenderan_dapat_diulang(): void
    {
        $letter = $this->issueLetter('3201010101800410', 'Pemohon Ulang');

        $letter->forceFill([
            'pdf_status' => Letter::PDF_FAILED,
            'pdf_failed_reason' => 'Gotenberg sedang dinyalakan ulang.',
        ])->save();

        Queue::fake();

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->from("/surat/arsip/{$letter->id}/cetak")
            ->post("/surat/arsip/{$letter->id}/pdf/ulangi")
            ->assertRedirect("/surat/arsip/{$letter->id}/cetak")
            ->assertSessionHas('success');

        Queue::assertPushed(RenderLetterPdf::class,
            fn (RenderLetterPdf $job): bool => $job->letterId === $letter->id);

        $letter->refresh();

        $this->assertSame(Letter::PDF_PENDING, $letter->pdf_status);
        $this->assertNull($letter->pdf_failed_reason,
            'Alasan kegagalan lama harus hilang saat perenderan diantrekan ulang.');
    }

    #[Test]
    #[TestDox('Mengulang perenderan surat di luar wilayah juga menghasilkan 404')]
    public function ulangi_di_luar_wilayah_menghasilkan_404(): void
    {
        $letter = $this->issueLetter('3201010101800411', 'Pemohon Ulang Luar');

        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN, $dusunLain))
            ->post("/surat/arsip/{$letter->id}/pdf/ulangi")
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Job tidak merender ulang surat yang PDF-nya sudah ada')]
    public function job_tidak_merender_ulang(): void
    {
        $letter = $this->issueLetter('3201010101800412', 'Pemohon Kembar');

        $this->renderer->lastHtml = null;

        (new RenderLetterPdf($letter->id))->handle(app(LetterPdfService::class));

        $this->assertNull($this->renderer->lastHtml,
            'Job kembar (dua kali klik "Ulangi") tidak boleh memanggil Chromium lagi.');
    }

    /* ===================== Pembantu ===================== */

    private function bindFailingRenderer(string $message): void
    {
        $this->app->instance(PdfRenderer::class, new class($message) implements PdfRenderer
        {
            public function __construct(private readonly string $message) {}

            public function render(string $html, PdfPaper $paper): string
            {
                throw new PdfRenderException($this->message);
            }

            public function name(): string
            {
                return 'uji-gagal';
            }

            public function isAvailable(): bool
            {
                return false;
            }
        });
    }

    private function issueLetter(string $nik, string $name): Letter
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

        $request = LetterRequest::query()->create([
            'letter_type_id' => $this->sktm->id,
            'ticket_number' => 'T'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
            'resident_id' => $resident->id,
            'channel' => 'walk_in',
            'purpose' => 'Pengajuan beasiswa',
            'form_data' => ['keperluan' => 'Pengajuan beasiswa'],
            'status' => LetterRequest::STATUS_VERIFIED,
            'current_step' => 1,
            'submitted_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect();

        return Letter::query()->where('request_id', $request->id)->firstOrFail();
    }
}
