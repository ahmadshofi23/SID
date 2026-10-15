<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * Driver untuk PENGUJIAN OTOMATIS saja.
 *
 * MENGAPA ADA DI KODE APLIKASI, bukan di tests/: driver dipilih lewat
 * config('pdf.driver'), dan satu-satunya tempat nama driver diterjemahkan
 * menjadi kelas adalah PdfRendererFactory. Menaruh kelas ini di tests/ berarti
 * pabriknya harus tahu tentang kelas yang tidak ada di produksi.
 *
 * Tanpa driver ini, SETIAP uji yang menerbitkan surat akan menjalankan
 * Chromium (queue di pengujian berjalan `sync`), dan rangkaian uji berubah
 * dari hitungan detik menjadi hitungan menit — lalu gagal di mesin CI yang
 * tidak punya peramban.
 *
 * Pemakaiannya di luar pengujian ditolak oleh PdfRendererFactory: PDF kosong
 * yang terbit sebagai surat resmi adalah kegagalan yang sangat mahal.
 */
final class FakePdfRenderer implements PdfRenderer
{
    /** HTML terakhir yang diminta dirender — diperiksa uji. */
    public ?string $lastHtml = null;

    public function name(): string
    {
        return 'fake';
    }

    public function render(string $html, PdfPaper $paper): string
    {
        $this->lastHtml = $html;

        return self::minimalPdf($paper);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * PDF satu halaman yang sah secara struktur, dengan MediaBox sesuai
     * ukuran kertas — sehingga uji pembandingan ukuran halaman memeriksa hal
     * yang sama pada ketiga driver.
     */
    public static function minimalPdf(PdfPaper $paper): string
    {
        $width = round($paper->widthPoints(), 2);
        $height = round($paper->heightPoints(), 2);

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] "
                ."/Resources << >> >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);

        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n"
            ."startxref\n".$xrefOffset."\n%%EOF\n";

        return $pdf;
    }
}
