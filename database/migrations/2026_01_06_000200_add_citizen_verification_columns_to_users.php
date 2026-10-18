<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Verifikasi akun warga — FR-AUT-08, FR-AUT-09, UC-15.
 *
 * Tabel `users` sejak Sprint 1 sudah menyiapkan `status`,
 * `verification_method`, `verified_by`, dan `verified_at` untuk alur ini.
 * Yang belum ada hanya dua: letak berkas KTP yang diunggah pemohon, dan
 * catatan petugas saat menolak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            /*
             * Disimpan di disk PRIVAT, sama seperti lampiran syarat surat:
             * ini foto KTP, dokumen paling sensitif yang dipegang sistem ini
             * selain NIK itu sendiri. Kolomnya menyimpan KUNCI OBJEK, bukan
             * URL — tidak ada alamat yang dapat beredar atau ditebak.
             */
            $t->string('verification_document_path', 500)->nullable()
                ->after('verification_method')
                ->comment('Foto KTP pemohon, disk privat. Dihapus setelah diverifikasi.');

            /*
             * Alasan penolakan, ditulis petugas dan DIBACAKAN kepada warga
             * yang datang bertanya. Tanpa kolom ini, akun yang ditolak hanya
             * berstatus nonaktif tanpa seorang pun tahu sebabnya — termasuk
             * petugas berikutnya yang menerima warga itu di loket.
             */
            $t->text('verification_note')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->dropColumn(['verification_document_path', 'verification_note']);
        });
    }
};
