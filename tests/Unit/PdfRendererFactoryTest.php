<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pdf\ChromeHeadlessPdfRenderer;
use App\Services\Pdf\FakePdfRenderer;
use App\Services\Pdf\GotenbergPdfRenderer;
use App\Services\Pdf\PdfPaper;
use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PdfRendererFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Pemilihan driver PDF — KEP-013 §3.
 *
 * Driver yang salah terpilih tidak menghasilkan galat; ia menghasilkan surat
 * dengan tata letak yang berbeda dari yang diharapkan kantor desa. Karena itu
 * pemilihannya diuji, bukan dianggap sepele.
 */
final class PdfRendererFactoryTest extends TestCase
{
    #[Test]
    #[TestDox('Konfigurasi menentukan driver: gotenberg di produksi, chrome di pengembangan')]
    public function konfigurasi_menentukan_driver(): void
    {
        $factory = new PdfRendererFactory;

        config()->set('pdf.driver', 'gotenberg');
        $gotenberg = $factory->make();

        config()->set('pdf.driver', 'chrome');
        $chrome = $factory->make();

        $this->assertInstanceOf(GotenbergPdfRenderer::class, $gotenberg);
        $this->assertSame('gotenberg', $gotenberg->name());

        $this->assertInstanceOf(ChromeHeadlessPdfRenderer::class, $chrome);
        $this->assertSame('chrome', $chrome->name());
    }

    #[Test]
    #[TestDox('Container menyerahkan driver sesuai config, bukan kelas tetap')]
    public function container_mengikuti_konfigurasi(): void
    {
        config()->set('pdf.driver', 'gotenberg');

        $this->assertInstanceOf(GotenbergPdfRenderer::class, $this->app->make(PdfRenderer::class));

        config()->set('pdf.driver', 'chrome');

        /*
         * Instance BARU, bukan yang tadi di-cache container. Uji pembandingan
         * dua driver (KEP-013 §4) mengganti driver di tengah proses; bila
         * ikatannya singleton, uji itu akan menguji driver yang sama dua kali
         * dan selalu lulus tanpa membuktikan apa pun.
         */
        $this->assertInstanceOf(ChromeHeadlessPdfRenderer::class, $this->app->make(PdfRenderer::class));
    }

    #[Test]
    #[TestDox('Driver tak dikenal menggagalkan permintaan, tidak diam-diam jatuh ke driver lain')]
    public function driver_tak_dikenal_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak dikenal');

        (new PdfRendererFactory)->make('tidak-ada');
    }

    #[Test]
    #[TestDox('Driver tiruan hanya tersedia saat pengujian otomatis')]
    public function driver_tiruan_hanya_untuk_pengujian(): void
    {
        $this->assertInstanceOf(FakePdfRenderer::class, (new PdfRendererFactory)->make('fake'));
    }

    #[Test]
    #[TestDox('Ukuran kertas F4 dikonversi benar ke inci dan titik PostScript')]
    public function ukuran_kertas_f4(): void
    {
        $paper = new PdfPaper(215, 330);

        // 215 mm ÷ 25,4 = 8,4646 in → × 72 = 609,45 pt
        $this->assertEqualsWithDelta(8.4646, $paper->widthInches(), 0.001);
        $this->assertEqualsWithDelta(12.9921, $paper->heightInches(), 0.001);
        $this->assertEqualsWithDelta(609.45, $paper->widthPoints(), 0.05);
        $this->assertEqualsWithDelta(935.43, $paper->heightPoints(), 0.05);
    }
}
