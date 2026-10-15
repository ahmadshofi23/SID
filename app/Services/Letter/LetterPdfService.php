<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\Letter;
use App\Services\Audit\AuditLogger;
use App\Services\Pdf\PdfPaper;
use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Perenderan & penyimpanan PDF surat — Sprint 5, KEP-013.
 *
 * Dipisahkan dari job agar dapat dipanggil langsung di uji dan perintah
 * diagnostik tanpa queue, dan agar job tetap tipis (hanya penjadwalan dan
 * penanganan kegagalan).
 */
final class LetterPdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly LetterPdfDocument $document,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Render PDF dari snapshot surat lalu simpan.
     *
     * @throws PdfRenderException
     */
    public function render(Letter $letter): Letter
    {
        $letter->forceFill([
            'pdf_status' => Letter::PDF_RENDERING,
            'pdf_failed_reason' => null,
        ])->save();

        $pdf = $this->renderer->render(
            $this->document->build($letter),
            PdfPaper::fromConfig(),
        );

        $path = $this->pathFor($letter);

        $this->disk()->put($path, $pdf);

        $letter->forceFill([
            'pdf_path' => $path,
            /*
             * Sidik jari berkas disimpan karena PDF terbit adalah arsip
             * hukum (ADR-003): bila suatu saat ada sengketa tentang isi
             * surat, hash inilah yang membuktikan berkas di penyimpanan
             * masih berkas yang sama dengan yang dibuat saat penerbitan.
             */
            'pdf_hash' => hash('sha256', $pdf),
            'pdf_status' => Letter::PDF_READY,
            'pdf_failed_reason' => null,
            'pdf_rendered_at' => now(),
        ])->save();

        $this->audit->log('letter.pdf_rendered', $letter, context: [
            'nomor_surat' => $letter->letter_number,
            'driver' => $this->renderer->name(),
            'ukuran_bita' => strlen($pdf),
        ]);

        return $letter;
    }

    /**
     * Catat kegagalan agar TERLIHAT PETUGAS.
     *
     * Surat yang PDF-nya gagal dirender tidak boleh menggantung di status
     * "menunggu" tanpa pesan (KEP-013): petugas harus tahu bahwa yang perlu
     * ia lakukan adalah menekan "Ulangi", bukan menunggu lebih lama.
     */
    public function markFailed(Letter $letter, string $reason): void
    {
        $letter->forceFill([
            'pdf_status' => Letter::PDF_FAILED,
            'pdf_failed_reason' => Str::limit($reason, 500),
        ])->save();

        $this->audit->log('letter.pdf_failed', $letter, context: [
            'nomor_surat' => $letter->letter_number,
            'driver' => $this->renderer->name(),
            'alasan' => Str::limit($reason, 300),
        ]);
    }

    /** Kembalikan ke antrean setelah kegagalan. */
    public function markQueued(Letter $letter): void
    {
        $letter->forceFill([
            'pdf_status' => Letter::PDF_PENDING,
            'pdf_failed_reason' => null,
        ])->save();
    }

    /**
     * Letak berkas di disk.
     *
     * Dikelompokkan per tahun terbit, memakai UUID surat — bukan nomor
     * suratnya. Nomor surat memuat garis miring (470/12/SKTM/2026) yang akan
     * menjadi direktori tak terduga di S3, dan memakai nomor berarti nama
     * berkas memberi tahu siapa pun yang melihat daftar objek berapa banyak
     * surat yang terbit bulan ini.
     */
    public function pathFor(Letter $letter): string
    {
        return 'surat/'.$letter->issued_year.'/'.$letter->id.'.pdf';
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('pdf.disk', 'local'));
    }

    public function driverName(): string
    {
        return $this->renderer->name();
    }
}
