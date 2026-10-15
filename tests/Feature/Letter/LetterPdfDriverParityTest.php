<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Models\Family;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Services\Letter\LetterPdfDocument;
use App\Services\Pdf\PdfPaper;
use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PdfRendererFactory;
use App\Support\NameNormalizer;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Pembandingan dua driver pada SURAT YANG SAMA — KEP-013 §4.
 *
 * MENGAPA UJI INI ADA. Surat desa dicetak di atas kertas F4 dan baris tanda
 * tangan harus muat di halaman pertama. Bila Chrome di mesin pengembang dan
 * Gotenberg di produksi memberi ukuran halaman yang berbeda, perbedaannya
 * tidak akan terlihat di layar siapa pun — ia muncul pertama kali sebagai
 * surat dua halaman di printer kantor desa, setelah ditandatangani.
 *
 * Uji ini DILEWATI bila salah satu driver tidak tersedia di mesin ini
 * (Gotenberg butuh runtime kontainer, Chrome butuh peramban terpasang).
 * Dilewati, bukan dipalsukan: uji yang selalu hijau karena tidak pernah
 * benar-benar memanggil driver lebih buruk daripada tidak ada uji.
 */
final class LetterPdfDriverParityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    #[TestDox('Gotenberg dan Chrome menghasilkan halaman F4 yang sama untuk surat yang sama')]
    public function dua_driver_menghasilkan_halaman_yang_sama(): void
    {
        $factory = new PdfRendererFactory;

        $drivers = [];

        foreach (['gotenberg', 'chrome'] as $name) {
            $driver = $factory->make($name);

            if (! $driver->isAvailable()) {
                $this->markTestSkipped(
                    'Driver "'.$name.'" tidak tersedia di mesin ini, sehingga keluaran kedua '
                    .'driver tidak dapat dibandingkan. KEP-013 §4 tetap menuntut pembandingan '
                    .'ini dijalankan sebelum rilis — di CI atau di mesin dengan Gotenberg hidup.'
                );
            }

            $drivers[$name] = $driver;
        }

        $letter = $this->issueLetter();
        $html = app(LetterPdfDocument::class)->build($letter);
        $paper = PdfPaper::fromConfig();

        $pages = [];
        $sizes = [];

        foreach ($drivers as $name => $driver) {
            $pdf = $driver->render($html, $paper);

            $this->assertStringStartsWith('%PDF-', $pdf, 'Driver '.$name.' tidak menghasilkan PDF.');

            $pages[$name] = $this->pageCount($pdf);
            $sizes[$name] = $this->firstMediaBox($pdf);

            // Berkas disimpan supaya tata letaknya dapat diperiksa dengan
            // mata bila uji ini gagal — perbedaan tata letak sulit dipahami
            // dari pesan assert saja.
            file_put_contents(storage_path('app/uji-pdf-'.$name.'.pdf'), $pdf);
        }

        $this->assertSame(
            $pages['gotenberg'], $pages['chrome'],
            'Jumlah halaman berbeda antara produksi dan pengembangan. Berkas pembanding '
            .'tersimpan di storage/app/uji-pdf-*.pdf.'
        );

        $this->assertSame(1, $pages['chrome'],
            'Surat satu lembar harus tetap satu halaman — baris tanda tangan tidak boleh pindah.');

        foreach (['gotenberg', 'chrome'] as $name) {
            $this->assertNotNull($sizes[$name], 'MediaBox driver '.$name.' tidak terbaca.');

            [$width, $height] = $sizes[$name];

            $this->assertEqualsWithDelta($paper->widthPoints(), $width, 1.0,
                'Lebar halaman driver '.$name.' bukan F4.');
            $this->assertEqualsWithDelta($paper->heightPoints(), $height, 1.0,
                'Tinggi halaman driver '.$name.' bukan F4.');
        }
    }

    #[Test]
    #[TestDox('Kedua driver melaporkan ketersediaannya tanpa melempar pengecualian')]
    public function ketersediaan_driver_dapat_diperiksa(): void
    {
        $factory = new PdfRendererFactory;

        foreach (['gotenberg', 'chrome'] as $name) {
            $driver = $factory->make($name);

            $this->assertInstanceOf(PdfRenderer::class, $driver);
            // Nilainya boleh apa saja — yang diuji adalah pemeriksaannya
            // sendiri tidak merusak apa pun di mesin tanpa Docker/Chrome.
            $this->assertIsBool($driver->isAvailable());
        }
    }

    private function pageCount(string $pdf): int
    {
        return max(
            preg_match_all('/\/Type\s*\/Page[^s]/', $pdf),
            1,
        );
    }

    /** @return array{float, float}|null */
    private function firstMediaBox(string $pdf): ?array
    {
        if (preg_match('/\/MediaBox\s*\[\s*0(?:\.\d+)?\s+0(?:\.\d+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m) !== 1) {
            return null;
        }

        return [(float) $m[1], (float) $m[2]];
    }

    private function issueLetter(): Letter
    {
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $family = new Family;
        $family->setKkNumber('3201010000009601');
        $family->forceFill(['region_id' => $rt->id, 'address' => 'Jl. Banding 1'])->save();

        $resident = new Resident;
        $resident->setNik('3201010101800501');
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $rt->id,
            'full_name' => 'Pemohon Banding',
            'full_name_norm' => NameNormalizer::normalize('Pemohon Banding'),
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

        $sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();

        $request = LetterRequest::query()->create([
            'letter_type_id' => $sktm->id,
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
