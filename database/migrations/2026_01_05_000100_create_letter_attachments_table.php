<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran syarat pengajuan surat — Sprint 5, KEP-013 §1.
 *
 * Syarat setiap jenis surat tersimpan sebagai JSON di `letter_types.requirements`
 * (docs/13 §2), berisi label saja — tidak ada id yang dapat dijadikan kunci asing.
 * Karena itu tabel ini menyimpan DUA penanda sekaligus:
 *
 *   requirement_index — posisi syarat saat berkas diunggah, dipakai untuk
 *                       mengelompokkan berkas pada layar;
 *   requirement_label — SALINAN labelnya saat itu.
 *
 * Label disalin karena daftar syarat dapat disunting kapan saja dari Jenis
 * Surat. Bila hanya indeksnya yang disimpan, menyisipkan satu syarat baru di
 * tengah daftar akan membuat seluruh berkas lama menunjuk syarat yang salah —
 * secara diam-diam, dan hanya terlihat saat petugas memeriksa berkas yang
 * ternyata bukan yang diminta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_attachments', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('request_id', 36);

            $t->unsignedSmallInteger('requirement_index')->nullable()
                ->comment('Posisi syarat saat diunggah; null = berkas tambahan');
            $t->string('requirement_label', 255)->nullable()
                ->comment('Salinan label syarat — tahan terhadap penyuntingan jenis surat');

            /*
             * Disk ikut disimpan, bukan hanya pathnya.
             *
             * Berkas lama tetap berada di disk tempat ia diunggah ketika desa
             * berpindah dari penyimpanan setempat ke S3. Tanpa kolom ini,
             * satu perubahan FILESYSTEM_DISK membuat seluruh arsip lampiran
             * lama tampak hilang.
             */
            $t->string('disk', 30);
            $t->string('path', 255)->comment('Nama berkas DIACAK — bukan nama asli dari peramban');

            // Nama asli hanya untuk ditampilkan dan dipakai saat mengunduh;
            // ia TIDAK pernah menjadi nama berkas di penyimpanan (NFR-SEC-05).
            $t->string('original_name', 255);

            // Tipe NYATA hasil pembacaan magic bytes, bukan Content-Type dari
            // peramban — yang sepenuhnya ditentukan pengirim.
            $t->string('mime', 100);
            $t->string('extension', 10);
            $t->unsignedInteger('size_bytes');
            $t->char('sha256', 64)->comment('Sidik jari isi berkas');

            $t->char('uploaded_by', 36)->nullable();
            $t->string('uploaded_by_name', 150)->nullable()
                ->comment('Disalin — tahan terhadap penghapusan akun');

            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->index(['request_id', 'requirement_index'], 'idx_la_request');

            $t->foreign('request_id', 'fk_la_request')
                ->references('id')->on('letter_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_attachments');
    }
};
