<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Penanda versi rumus hash pada audit log — KEP-020.
 *
 * Rumus hash baris audit berubah di KEP-020 karena dua cacat yang berlawanan
 * arah, keduanya ditemukan uji beban Sprint 7 (docs/23 §7):
 *
 *   1. Kolom `changes` tidak pernah ikut di-hash, sehingga nilai lama/baru
 *      data penduduk dapat diubah di luar aplikasi tanpa merusak rantai.
 *   2. Kedua kolom JSON di-hash menurut URUTAN KUNCI saat ditulis, padahal
 *      MySQL menyimpan kolom JSON dalam bentuk kanoniknya sendiri dan
 *      mengembalikan kuncinya terurut. Setiap baris yang kuncinya tidak
 *      kebetulan sudah urut karena itu dilaporkan "rusak" walau utuh.
 *
 * Mengubah rumusnya membatalkan `row_hash` SELURUH baris yang sudah ada. Kolom
 * ini yang membuat hal itu dapat ditanggung tanpa menghitung ulang satu baris
 * pun: verifikasi tahu rumus mana yang berlaku untuk setiap baris.
 *
 * MENGAPA BAWAANNYA 1 DAN BUKAN 2 — ini keputusan, bukan kelalaian:
 *
 *   - Baris yang sudah ada mendapat 1 tanpa satu pernyataan UPDATE pun. Itu
 *     syarat mutlak di tabel ini: hak akses MySQL (sid:harden-audit-log)
 *     menolak UPDATE atas audit_logs, dan memang harus menolaknya.
 *   - Baris BARU yang disisipkan sesuatu yang tidak mengenal kolom ini (mis.
 *     penyisipan massal lewat DB::table()) juga mendapat 1 — dan itu jawaban
 *     yang benar: penyisip yang tidak tahu tentang versi hash hampir pasti
 *     juga tidak memakai rumus yang berlaku sekarang. Bawaan 2 akan membuat
 *     baris semacam itu MENGAKU v2 lalu gagal verifikasi; bawaan 1 membuatnya
 *     jujur menyebut dirinya tidak dapat dihitung ulang.
 *
 * App\Models\AuditLog mengisi kolom ini secara eksplisit dari konstanta
 * HASH_VERSION pada setiap pencatatan, jadi jalur normal tidak bergantung
 * pada bawaan ini sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t): void {
            $t->unsignedTinyInteger('hash_version')->default(1)->after('row_hash')
                ->comment('Rumus hash yang dipakai baris ini — KEP-020');
        });
    }

    public function down(): void
    {
        /*
         * Catatan untuk siapa pun yang menjalankan ini: menurunkan migrasi
         * tidak memulihkan rantai lama. Baris yang ditulis dengan rumus v2
         * tetap ber-hash v2, dan tanpa kolom ini verifikasi kehilangan satu-
         * satunya cara mengetahuinya — ia akan melaporkan seluruh baris v2
         * sebagai rusak. Turunkan migrasi ini hanya pada basis data yang
         * memang dibuang.
         */
        Schema::table('audit_logs', function (Blueprint $t): void {
            $t->dropColumn('hash_version');
        });
    }
};
