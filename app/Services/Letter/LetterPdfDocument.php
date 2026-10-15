<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\Letter;
use App\Models\VillageProfile;
use App\Services\Pdf\PdfPaper;
use App\Support\VillageTime;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Menyusun dokumen HTML MANDIRI untuk dirender menjadi PDF.
 *
 * "Mandiri" bukan kerapian, melainkan syarat: di produksi Chromium berjalan
 * di kontainer Gotenberg yang terpisah dan tidak dapat memuat `/storage/...`
 * milik aplikasi, dan di pengembangan Chrome membuka berkas lewat `file://`
 * yang tidak punya host sama sekali. Karena itu logo dan QR DITANAMKAN ke
 * dalam HTML, bukan ditautkan.
 *
 * BADAN SURATNYA SENDIRI TIDAK DIRAKIT ULANG di sini (ADR-003): isinya
 * disalin apa adanya dari `letters.rendered_html`, snapshot permanen yang
 * dibuat saat surat ditandatangani. Yang ditambahkan kelas ini hanyalah
 * bingkai kertas — kop desa, judul, dan blok tanda tangan + QR — persis
 * seperti yang dilakukan halaman cetak.
 */
final class LetterPdfDocument
{
    public function __construct(private readonly LetterQrCode $qr) {}

    public function build(Letter $letter): string
    {
        $letter->loadMissing('letterType');

        $village = VillageProfile::current();
        $paper = PdfPaper::fromConfig();

        return View::make('surat.pdf', [
            'letter' => $letter,
            'village' => $village,
            'paper' => $paper,
            'titleOnLetter' => $letter->letterType->title_on_letter,
            'issuedLong' => VillageTime::local($letter->issued_at)
                ->locale('id')->translatedFormat('j F Y'),
            'qrSvg' => $this->qr->svg($letter->verification_code),
            'logoDataUri' => $this->logoDataUri($village->logo_path),
        ])->render();
    }

    /**
     * Logo desa sebagai data URI.
     *
     * Mengembalikan null bila logo belum diunggah atau berkasnya hilang —
     * surat tetap tercetak dengan kop tanpa lambang, karena menggagalkan
     * penerbitan PDF hanya gara-gara lambang desa belum diunggah akan
     * menghentikan pelayanan.
     */
    private function logoDataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        if ($contents === null || $contents === '') {
            return null;
        }

        $mime = $disk->mimeType($path);

        return 'data:'.($mime === false ? 'image/png' : $mime)
            .';base64,'.base64_encode($contents);
    }
}
