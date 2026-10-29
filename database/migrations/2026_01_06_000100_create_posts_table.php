<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Konten portal: berita, pengumuman, agenda — docs/05 §3.6, FR-PRT-02/03.
 *
 * SATU TABEL UNTUK TIGA JENIS, bukan tiga tabel. Ketiganya punya judul, isi,
 * gambar, status terbit, dan slug yang sama persis; yang membedakan hanyalah
 * tiga kolom agenda dan cara mengurutkannya. Tiga tabel berarti tiga layar
 * penyuntingan yang harus diperbaiki bersamaan setiap kali ada perubahan
 * kecil — dan yang terjadi adalah dua diperbaiki, satu tertinggal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->enum('type', ['berita', 'pengumuman', 'agenda']);
            $t->string('slug', 200);
            $t->string('title', 250);
            $t->string('excerpt', 500)->nullable();
            $t->mediumText('body');
            $t->string('cover_path', 255)->nullable();
            $t->string('category_code', 40)->nullable();
            $t->json('tags')->nullable();

            // Khusus agenda (FR-PRT-03).
            $t->dateTime('event_start_at')->nullable();
            $t->dateTime('event_end_at')->nullable();
            $t->string('event_location', 255)->nullable();

            $t->enum('status', ['draft', 'terbit', 'arsip'])->default('draft');

            /*
             * PENJADWALAN TERBIT ADA DI SINI, bukan di sebuah job terjadwal.
             *
             * Konten berstatus `terbit` dengan published_at di masa depan
             * belum terlihat publik; pagarnya adalah perbandingan waktu di
             * dalam KUERI (Post::scopePublished). Job yang harus berjalan
             * agar tulisan muncul adalah satu hal lagi yang dapat mati diam-
             * diam di server desa — dan kegagalannya baru ketahuan ketika
             * pengumuman yang dijadwalkan tidak pernah terbit.
             */
            $t->dateTime('published_at')->nullable();

            $t->char('author_id', 36)->nullable();
            $t->unsignedInteger('view_count')->default(0);
            $t->string('meta_title', 200)->nullable();
            $t->string('meta_description', 300)->nullable();

            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->dateTime('deleted_at')->nullable();

            // Keunikan bersyarat: slug unik hanya di antara yang belum dihapus,
            // pola yang sama dengan `users.email_active` (docs/05 §3.4).
            $t->string('slug_active', 200)
                ->storedAs('IF(deleted_at IS NULL, slug, NULL)');
            $t->unique('slug_active', 'uq_posts_slug');

            $t->index(['type', 'status', 'published_at'], 'idx_posts_public');
            $t->index('event_start_at', 'idx_posts_event');

            $t->foreign('author_id', 'fk_posts_author')
                ->references('id')->on('users')->nullOnDelete();
        });

        /*
         * Agenda tanpa waktu mulai adalah agenda yang tidak dapat diurutkan
         * maupun ditampilkan sebagai "kegiatan mendatang". Ditegakkan di
         * tingkat basis data, bukan hanya di FormRequest: impor atau perbaikan
         * data lewat SQL tidak melewati lapis validasi aplikasi.
         */
        DB::statement(
            "ALTER TABLE posts
                ADD CONSTRAINT chk_posts_agenda_mulai
                CHECK (type <> 'agenda' OR event_start_at IS NOT NULL)"
        );

        DB::statement(
            'ALTER TABLE posts
                ADD CONSTRAINT chk_posts_agenda_selesai
                CHECK (event_end_at IS NULL OR event_end_at >= event_start_at)'
        );

        /*
         * Terbit tanpa tanggal terbit tidak punya arti: kuerinya menyaring
         * published_at, jadi baris seperti itu akan tersembunyi selamanya
         * tanpa ada yang mengerti mengapa.
         */
        DB::statement(
            "ALTER TABLE posts
                ADD CONSTRAINT chk_posts_terbit_bertanggal
                CHECK (status <> 'terbit' OR published_at IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
