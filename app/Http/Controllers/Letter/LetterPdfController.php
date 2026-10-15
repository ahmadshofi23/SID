<?php

declare(strict_types=1);

namespace App\Http\Controllers\Letter;

use App\Http\Controllers\Controller;
use App\Jobs\RenderLetterPdf;
use App\Models\Letter;
use App\Repositories\LetterRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Letter\LetterPdfService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduhan PDF surat — Sprint 5, KEP-013.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class LetterPdfController extends Controller
{
    public function download(
        string $id,
        LetterRepository $letters,
        LetterPdfService $pdf,
        AuditLogger $audit,
    ): StreamedResponse {
        $this->authorize('surat.lihat');

        /*
         * findOrFail() repository, bukan Letter::findOrFail().
         *
         * Surat DI LUAR wilayah atau keluarga pengguna menghasilkan 404,
         * bukan 403 (docs/06 §4): 403 akan memastikan bagi penyerang bahwa
         * surat dengan id itu memang ada. Ini pula sebabnya unduhan tidak
         * boleh memakai URL pra-tanda-tangan yang dibagikan: pembatasannya
         * harus diperiksa pada setiap permintaan.
         */
        $letter = $letters->findOrFail($id);

        if (! $letter->hasPdf()) {
            abort(409, $this->notReadyMessage($letter));
        }

        $path = (string) $letter->pdf_path;
        $disk = $pdf->disk();

        if (! $disk->exists($path)) {
            /*
             * Baris mengatakan "selesai" tetapi berkasnya tidak ada —
             * biasanya karena disk pengembangan dibersihkan, atau berkas
             * dipindahkan di luar aplikasi. Statusnya dikembalikan ke
             * "gagal" supaya petugas melihat tombol "Ulangi", bukan
             * tombol unduh yang selalu menghasilkan galat.
             */
            $pdf->markFailed($letter, 'Berkas PDF tidak ditemukan di penyimpanan. Silakan ulangi perenderan.');

            abort(409, 'Berkas PDF surat ini tidak ditemukan di penyimpanan. Silakan ulangi perenderan.');
        }

        $audit->log('letter.pdf_downloaded', $letter, context: [
            'nomor_surat' => $letter->letter_number,
        ]);

        return $disk->download($path, $letter->pdfFileName());
    }

    /** Ulangi perenderan yang gagal. */
    public function retry(string $id, LetterRepository $letters, LetterPdfService $pdf): RedirectResponse
    {
        $this->authorize('surat.lihat');

        $letter = $letters->findOrFail($id);

        if ($letter->pdf_status === Letter::PDF_RENDERING) {
            return back()->with('error', 'Perenderan PDF surat ini sedang berjalan. Tunggu sebentar lalu muat ulang halaman.');
        }

        $pdf->markQueued($letter);

        RenderLetterPdf::dispatch($letter->id);

        return back()->with(
            'success',
            'Perenderan PDF surat '.$letter->letter_number.' dimasukkan kembali ke antrean.',
        );
    }

    private function notReadyMessage(Letter $letter): string
    {
        return match ($letter->pdf_status) {
            Letter::PDF_FAILED => 'Perenderan PDF surat ini gagal: '
                .($letter->pdf_failed_reason ?? 'sebab tidak tercatat.')
                .' Surat tetap dapat dicetak dari halaman cetak.',
            Letter::PDF_RENDERING => 'PDF surat ini sedang dirender. Muat ulang halaman sebentar lagi.',
            default => 'PDF surat ini belum dirender. Muat ulang halaman sebentar lagi, '
                .'atau ulangi perenderan bila status tidak berubah.',
        };
    }
}
