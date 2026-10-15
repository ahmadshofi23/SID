<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use InvalidArgumentException;

/**
 * Pemilih driver PDF — satu-satunya tempat nama driver diterjemahkan menjadi
 * kelas (KEP-013 §3).
 */
final class PdfRendererFactory
{
    public const DRIVERS = ['gotenberg', 'chrome'];

    public function make(?string $driver = null): PdfRenderer
    {
        $driver ??= (string) config('pdf.driver', 'gotenberg');
        $timeout = (int) config('pdf.timeout', 60);

        return match ($driver) {
            'gotenberg' => new GotenbergPdfRenderer(
                rtrim((string) config('pdf.gotenberg.url'), '/'),
                $timeout,
            ),
            'chrome' => new ChromeHeadlessPdfRenderer(
                (string) config('pdf.chrome.binary'),
                $timeout,
            ),
            /*
             * Hanya untuk pengujian otomatis, dan ditolak di luar itu: PDF
             * kosong yang terbit sebagai surat resmi desa adalah kegagalan
             * yang jauh lebih mahal daripada galat pemasangan.
             */
            'fake' => app()->runningUnitTests()
                ? new FakePdfRenderer
                : throw new InvalidArgumentException(
                    'Driver PDF "fake" hanya boleh dipakai saat pengujian otomatis.'
                ),
            /*
             * Driver tak dikenal menggagalkan permintaan di tempat, bukan
             * diam-diam jatuh ke salah satu driver. Salah ketik PDF_DRIVER di
             * produksi akan terlihat sebagai galat pemasangan, bukan sebagai
             * surat yang tata letaknya diam-diam berbeda.
             */
            default => throw new InvalidArgumentException(
                'Driver PDF "'.$driver.'" tidak dikenal. Pilihan: '
                .implode(', ', self::DRIVERS).'.'
            ),
        };
    }
}
