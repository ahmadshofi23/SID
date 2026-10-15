<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * Ukuran kertas dalam MILIMETER.
 *
 * Milimeter, bukan piksel: ini dokumen kertas, dan `px` tidak punya arti
 * tetap di printer. Konversi ke inci hanya dilakukan di perbatasan driver
 * yang memang menuntut inci (Gotenberg).
 */
final readonly class PdfPaper
{
    public function __construct(
        public float $widthMm,
        public float $heightMm,
    ) {}

    /** Ukuran dari config/pdf.php — F4 bagi surat desa. */
    public static function fromConfig(): self
    {
        return new self(
            (float) config('pdf.paper.width_mm', 215),
            (float) config('pdf.paper.height_mm', 330),
        );
    }

    public function widthInches(): float
    {
        return $this->widthMm / 25.4;
    }

    public function heightInches(): float
    {
        return $this->heightMm / 25.4;
    }

    /** Ukuran dalam titik PostScript — satuan `MediaBox` di dalam PDF. */
    public function widthPoints(): float
    {
        return $this->widthInches() * 72;
    }

    public function heightPoints(): float
    {
        return $this->heightInches() * 72;
    }
}
