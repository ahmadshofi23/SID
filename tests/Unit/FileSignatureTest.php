<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Upload\FileSignature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pengenalan tipe berkas dari isinya — NFR-SEC-05.
 */
final class FileSignatureTest extends TestCase
{
    #[Test]
    #[TestDox('Tiga tipe yang diterima dikenali dari magic bytes-nya')]
    public function tipe_sah_dikenali(): void
    {
        $this->assertSame('pdf', FileSignature::fromHead('%PDF-1.7'."\n".'1 0 obj')?->extension);
        $this->assertSame('jpg', FileSignature::fromHead("\xFF\xD8\xFF\xE0".'JFIF')?->extension);
        $this->assertSame('png', FileSignature::fromHead("\x89PNG\x0D\x0A\x1A\x0AIHDR")?->extension);

        $this->assertSame('image/jpeg', FileSignature::fromHead("\xFF\xD8\xFF\xDB")?->mime);
    }

    #[Test]
    #[TestDox('HTML yang menyamar sebagai PDF tidak dikenali sebagai tipe apa pun')]
    public function html_bukan_pdf(): void
    {
        $this->assertNull(FileSignature::fromHead('<!doctype html><html><body>'));
        $this->assertNull(FileSignature::fromHead('<?php system($_GET["c"]); ?>'));
        $this->assertNull(FileSignature::fromHead('GIF89a'), 'GIF di luar daftar yang diterima.');
        $this->assertNull(FileSignature::fromHead('PK'."\x03\x04"), 'ZIP/DOCX di luar daftar.');
        $this->assertNull(FileSignature::fromHead(''));
    }

    #[Test]
    #[TestDox('"%PDF-" yang diselipkan di tengah berkas HTML tetap ditolak')]
    public function pdf_di_tengah_berkas_ditolak(): void
    {
        /*
         * Spesifikasi PDF membolehkan sampah sebelum tanda ini, dan banyak
         * pembaca memaafkannya. Kelonggaran itu sengaja tidak diikuti:
         * berkas semacam ini akan dibaca sebagai HTML oleh peramban dan
         * sebagai PDF oleh pemeriksa yang longgar — persis celah yang
         * hendak ditutup.
         */
        $this->assertNull(FileSignature::fromHead('<html>%PDF-1.4</html>'));
    }

    #[Test]
    #[TestDox('Berkas JPEG yang dinamai .jpeg maupun .jpg sama-sama diterima')]
    public function jpeg_dan_jpg_sama(): void
    {
        $jpeg = FileSignature::fromHead("\xFF\xD8\xFF\xE1");

        $this->assertTrue($jpeg?->matchesClaimedExtension('jpg'));
        $this->assertTrue($jpeg?->matchesClaimedExtension('JPEG'));
        $this->assertFalse($jpeg?->matchesClaimedExtension('png'));
    }

    #[Test]
    #[TestDox('Berkas yang tidak dapat dibaca menghasilkan null, bukan galat')]
    public function berkas_tidak_ada(): void
    {
        $this->assertNull(FileSignature::detect('/tidak/ada/berkas-ini.pdf'));
    }
}
