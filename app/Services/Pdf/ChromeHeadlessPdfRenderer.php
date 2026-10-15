<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Driver pengembangan — Google Chrome setempat (KEP-013 §3).
 *
 * BUKAN untuk produksi: menjalankan peramban penuh di server aplikasi berarti
 * aplikasi web dan mesin render berbagi memori, batas proses, dan permukaan
 * serangan yang sama. Di produksi Chromium diisolasi di kontainer Gotenberg.
 *
 * Driver ini ada supaya pekerjaan persuratan dapat diselesaikan di mesin
 * pengembang yang tidak punya runtime kontainer.
 */
final readonly class ChromeHeadlessPdfRenderer implements PdfRenderer
{
    public function __construct(
        private string $binary,
        private int $timeout = 60,
    ) {}

    public function name(): string
    {
        return 'chrome';
    }

    public function render(string $html, PdfPaper $paper): string
    {
        if (! is_executable($this->binary)) {
            throw new PdfRenderException(
                'Google Chrome tidak ditemukan di "'.$this->binary.'". '
                .'Setel CHROME_BINARY di .env, atau pakai driver gotenberg.'
            );
        }

        /*
         * Satu direktori kerja SENDIRI per render, bukan satu berkas di
         * direktori bersama.
         *
         * Chrome juga menulis profil penggunanya ke sana. Dua render yang
         * berjalan bersamaan dengan direktori profil yang sama akan saling
         * mengunci, dan gejalanya adalah render yang menggantung sampai
         * batas waktu — bukan galat yang jelas.
         */
        $workdir = sys_get_temp_dir().'/sid-pdf-'.Str::uuid7()->toString();

        if (! @mkdir($workdir, 0700) && ! is_dir($workdir)) {
            throw new PdfRenderException('Tidak dapat membuat direktori sementara untuk render PDF.');
        }

        $input = $workdir.'/surat.html';
        $output = $workdir.'/surat.pdf';

        try {
            file_put_contents($input, $html);

            $result = Process::timeout($this->timeout)->run([
                $this->binary,
                '--headless=new',
                '--disable-gpu',
                '--no-first-run',
                '--no-default-browser-check',
                '--user-data-dir='.$workdir.'/profil',
                /*
                 * Ukuran kertas TIDAK diberikan lewat argumen.
                 *
                 * --print-to-pdf memakai aturan `@page` dari CSS halaman,
                 * dan di situlah F4 ditetapkan — sama persis dengan yang
                 * dihormati Gotenberg lewat preferCssPageSize (KEP-013 §4).
                 */
                '--no-pdf-header-footer',
                '--run-all-compositor-stages-before-draw',
                // Jam virtual: Chrome tidak menunggu animasi/`setTimeout`
                // yang tidak pernah selesai sebelum mencetak.
                '--virtual-time-budget=5000',
                '--print-to-pdf='.$output,
                'file://'.$input,
            ]);

            if (! $result->successful() || ! is_file($output)) {
                throw new PdfRenderException(
                    'Chrome gagal merender PDF (kode '.$result->exitCode().'): '
                    .mb_substr(trim($result->errorOutput()), 0, 300)
                );
            }

            $pdf = (string) file_get_contents($output);

            if (! str_starts_with($pdf, '%PDF-')) {
                throw new PdfRenderException('Chrome menghasilkan berkas yang bukan PDF.');
            }

            return $pdf;
        } finally {
            $this->removeDirectory($workdir);
        }
    }

    public function isAvailable(): bool
    {
        if (! is_executable($this->binary)) {
            return false;
        }

        try {
            return Process::timeout(10)->run([$this->binary, '--version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
