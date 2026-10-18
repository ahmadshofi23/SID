<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Notifikasi dalam aplikasi — FR-SRT-14, BR-11.
 *
 * MENGAPA MEMAKAI BENTUK TABEL BAWAAN LARAVEL, bukan skema buatan sendiri
 * seperti tabel lain di proyek ini: FR-SRT-14 menyatakan notifikasi
 * in-app pada Fase 1 dan email/WhatsApp pada Fase 2. Dengan lapis notifikasi
 * bawaan, Fase 2 berarti MENAMBAH SATU KANAL pada kelas notifikasi yang sudah
 * ada — bukan menulis ulang pengirimannya. Tabel buatan sendiri akan terlihat
 * lebih rapi hari ini dan menjadi pekerjaan ganda enam bulan lagi.
 *
 * Konsekuensi yang diterima: kolom `data` berupa JSON, bukan kolom bernama.
 * Karena itu bentuk isinya DIKUNCI di satu tempat (App\Notifications\*), dan
 * tidak ada kode lain yang boleh menyusun isi `data` sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->string('type', 150);
            $t->string('notifiable_type', 60);
            $t->char('notifiable_id', 36);
            $t->json('data');
            $t->dateTime('read_at')->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            /*
             * Indeks ini melayani dua kueri yang dijalankan HAMPIR SETIAP
             * HALAMAN: jumlah yang belum dibaca untuk lonceng, dan daftar
             * notifikasi terbaru. Keduanya menyaring penerima lalu mengurut
             * waktu, jadi urutan kolomnya mengikuti itu.
             */
            $t->index(['notifiable_type', 'notifiable_id', 'read_at'], 'idx_notifications_penerima');
            $t->index(['notifiable_type', 'notifiable_id', 'created_at'], 'idx_notifications_waktu');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
