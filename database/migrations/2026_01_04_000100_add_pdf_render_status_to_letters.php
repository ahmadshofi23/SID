<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status perenderan PDF — Sprint 5, KEP-013.
 *
 * MENGAPA PERLU KOLOM STATUS, bukan sekadar `pdf_path IS NULL`.
 *
 * Perenderan berjalan di queue (ADR-003). `pdf_path` yang masih kosong punya
 * tiga arti berbeda bagi petugas di loket: sedang diproses, gagal, atau
 * worker-nya memang tidak jalan. Tanpa membedakannya, halaman surat hanya
 * dapat menampilkan "menunggu" selamanya tanpa alasan — gejala yang sangat
 * membingungkan karena tidak ada yang tampak rusak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letters', function (Blueprint $t): void {
            $t->enum('pdf_status', ['menunggu', 'diproses', 'selesai', 'gagal'])
                ->default('menunggu')
                ->after('pdf_hash');

            // Pesan yang DIBACA PETUGAS, bukan jejak tumpukan. Lihat
            // App\Services\Pdf\PdfRenderException.
            $t->text('pdf_failed_reason')->nullable()->after('pdf_status');

            $t->dateTime('pdf_rendered_at')->nullable()->after('pdf_failed_reason');
        });
    }

    public function down(): void
    {
        Schema::table('letters', function (Blueprint $t): void {
            $t->dropColumn(['pdf_status', 'pdf_failed_reason', 'pdf_rendered_at']);
        });
    }
};
