<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Auth\AccessScope;
use App\Models\Letter;
use App\Repositories\LetterRepository;
use App\Services\Letter\LetterPdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Render PDF surat di latar belakang — ADR-003 (NFR-PERF-05), KEP-013.
 *
 * Perenderan TIDAK dilakukan di dalam permintaan penerbitan: Chromium
 * memerlukan waktu sekian detik, dan membuat petugas menunggunya berarti
 * penerbitan surat gagal setiap kali perender sedang sibuk — padahal nomor
 * suratnya sudah sah dan suratnya sudah dapat dicetak dari layar.
 */
final class RenderLetterPdf implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    /**
     * Dicoba ulang, berbeda dari ProcessResidentImport.
     *
     * Di sini pengulangan aman: perenderan murni membaca snapshot dan menulis
     * satu berkas ke jalur yang tetap, jadi percobaan kedua menimpa hasil
     * percobaan pertama alih-alih menggandakan apa pun. Penyebab kegagalan
     * yang paling sering — Gotenberg sedang dinyalakan ulang — justru hilang
     * sendiri pada percobaan berikutnya.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $letterId) {}

    public function handle(LetterPdfService $pdf): void
    {
        $letter = $this->findLetter();

        if ($letter === null) {
            return;
        }

        // Sudah dirender (mis. job kembar dari dua kali klik "Ulangi"):
        // tidak perlu memanggil Chromium lagi.
        if ($letter->hasPdf()) {
            return;
        }

        $pdf->render($letter);
    }

    public function failed(Throwable $exception): void
    {
        $letter = $this->findLetter();

        if ($letter === null) {
            return;
        }

        /*
         * Kegagalan DITULIS ke barisnya, bukan hanya ke log.
         *
         * Petugas loket tidak membaca log worker. Tanpa ini, surat akan
         * tampak "sedang diproses" selamanya dan ia akan menunggu sesuatu
         * yang tidak akan pernah datang (KEP-013).
         */
        app(LetterPdfService::class)->markFailed($letter, $exception->getMessage());
    }

    /**
     * Pencarian surat memakai LINGKUP SISTEM.
     *
     * Job berjalan tanpa pengguna yang masuk, sehingga AccessScope dari
     * container adalah lingkup tamu dan tidak akan menemukan surat apa pun.
     * Lingkup sistem dipakai secara eksplisit di sini — bukan dengan
     * melewati lapis repository (docs/04 §4.1).
     */
    private function findLetter(): ?Letter
    {
        return (new LetterRepository(AccessScope::system()))->find($this->letterId);
    }
}
