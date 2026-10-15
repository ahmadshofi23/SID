<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * Perender PDF — KEP-013 §3.
 *
 * Pemanggil TIDAK BOLEH memanggil Gotenberg (atau Chrome) secara langsung.
 * Dua alasan:
 *
 *   1. Mesin pengembang di macOS tidak selalu punya runtime kontainer, jadi
 *      pengembangan harus bisa berjalan tanpa Gotenberg.
 *   2. Ketika suatu saat Chromium diganti, hanya kelas di dalam namespace ini
 *      yang berubah — bukan alur penerbitan surat.
 */
interface PdfRenderer
{
    /**
     * Render satu dokumen HTML UTUH (dengan <html>, CSS, dan gambar yang
     * sudah tertanam) menjadi berkas PDF.
     *
     * HTML harus mandiri: driver tidak menjamin ada akses jaringan ke
     * aplikasi, dan di produksi Chromium berjalan di kontainer lain yang
     * tidak dapat memuat `/storage/...` milik aplikasi.
     *
     * @return string isi berkas PDF mentah
     *
     * @throws PdfRenderException
     */
    public function render(string $html, PdfPaper $paper): string;

    /** Nama driver untuk log, pesan galat, dan pengujian pembandingan. */
    public function name(): string;

    /**
     * Apakah driver ini siap dipakai di mesin ini?
     *
     * Dipakai perintah diagnostik dan uji pembandingan dua driver, bukan
     * sebagai syarat sebelum setiap render: pemeriksaan per render akan
     * menambah satu bolak-balik jaringan pada setiap surat.
     */
    public function isAvailable(): bool;
}
