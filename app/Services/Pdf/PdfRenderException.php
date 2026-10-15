<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Perenderan PDF gagal.
 *
 * Pesannya SENGAJA dapat dibaca petugas, bukan hanya pengembang: ia berakhir
 * di kolom `letters.pdf_failed_reason` dan ditampilkan pada halaman cetak.
 * Surat yang PDF-nya gagal dirender tidak boleh menggantung di status
 * "menunggu" tanpa keterangan apa pun (KEP-013).
 */
final class PdfRenderException extends RuntimeException {}
