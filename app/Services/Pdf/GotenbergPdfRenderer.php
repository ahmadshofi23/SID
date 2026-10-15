<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Driver produksi — Chromium di dalam kontainer Gotenberg (KEP-004, ADR-003).
 */
final readonly class GotenbergPdfRenderer implements PdfRenderer
{
    public function __construct(
        private string $baseUrl,
        private int $timeout = 60,
    ) {}

    public function name(): string
    {
        return 'gotenberg';
    }

    public function render(string $html, PdfPaper $paper): string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->attach('files', $html, 'index.html')
                ->post($this->baseUrl.'/forms/chromium/convert/html', [
                    /*
                     * preferCssPageSize WAJIB true.
                     *
                     * Inilah yang membuat keluaran Gotenberg dan Chrome
                     * setempat sama (KEP-013 §4): ukuran kertas ditentukan
                     * satu tempat — aturan `@page` di template surat —
                     * bukan oleh opsi driver yang mudah berbeda di antara
                     * dua lingkungan. paperWidth/paperHeight tetap dikirim
                     * sebagai cadangan bila template kehilangan `@page`.
                     */
                    'preferCssPageSize' => 'true',
                    'paperWidth' => (string) round($paper->widthInches(), 4),
                    'paperHeight' => (string) round($paper->heightInches(), 4),

                    // Margin diatur template (padding lembar), bukan driver —
                    // lihat alasan yang sama di atas.
                    'marginTop' => '0',
                    'marginBottom' => '0',
                    'marginLeft' => '0',
                    'marginRight' => '0',

                    // Tanpa ini, blok berlatar (mis. tanda air pembatalan)
                    // hilang dari cetakan.
                    'printBackground' => 'true',
                ]);
        } catch (ConnectionException $e) {
            throw new PdfRenderException(
                'Layanan perender PDF (Gotenberg) tidak dapat dihubungi di '
                .$this->baseUrl.'. Hubungi administrator sistem.',
                previous: $e,
            );
        }

        if ($response->failed()) {
            /*
             * Badan galat Gotenberg TIDAK diteruskan apa adanya ke petugas:
             * isinya jejak internal Chromium. Yang berguna bagi petugas
             * adalah bahwa perenderannya gagal dan dapat diulang; rinciannya
             * tetap masuk log aplikasi lewat pengecualian ini.
             */
            throw new PdfRenderException(
                'Layanan perender PDF menolak permintaan (HTTP '.$response->status().'): '
                .mb_substr(trim($response->body()), 0, 300)
            );
        }

        $body = $response->body();

        if (! str_starts_with($body, '%PDF-')) {
            throw new PdfRenderException(
                'Layanan perender PDF mengembalikan data yang bukan PDF.'
            );
        }

        return $body;
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->get($this->baseUrl.'/health')->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
