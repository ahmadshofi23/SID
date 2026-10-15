<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `letters.issued_year` menjadi kolom biasa, bukan generated column.
 *
 * MENGAPA DIUBAH (ditemukan saat menelusuri alur persuratan di peramban):
 *
 * Kolom ini semula `YEAR(issued_at)`, dihitung MySQL dari waktu yang
 * disimpan dalam UTC. Namun nomor surat dan penghitung nomor memakai
 * KALENDER DESA (Asia/Jakarta pada pemasangan baku) — lihat
 * App\Support\VillageTime.
 *
 * Keduanya berbeda tepat di pergantian tahun: surat yang terbit 1 Januari
 * pukul 02.00 WIB tersimpan sebagai 31 Desember 19.00 UTC, sehingga
 * issued_year berisi tahun LAMA sementara nomor suratnya menyebut tahun
 * BARU dan penghitungnya pun tahun baru.
 *
 * Akibatnya indeks unik `uq_letters_seq` — jaring pengaman terakhir terhadap
 * nomor surat ganda (ADR-001) — menjaga pasangan tahun yang salah, dan
 * nomor 1 tahun baru dapat terbit dua kali tanpa ditolak basis data.
 *
 * Jaring pengaman tidak boleh bergantung pada zona waktu server. Karena itu
 * tahunnya sekarang DITULIS APLIKASI, dari sumber yang sama dengan yang
 * dipakai penomoran.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Indeks pengganti dibuat LEBIH DULU untuk kunci asing.
         *
         * `uq_letters_seq` berawalan `letter_type_id`, sehingga MySQL
         * memakainya sebagai indeks penopang kunci asing fk_letters_type dan
         * menolak membuangnya ("needed in a foreign key constraint") selama
         * tidak ada indeks lain yang bisa menggantikannya.
         */
        Schema::table('letters', function ($table): void {
            $table->index('letter_type_id', 'idx_letters_type');
        });

        Schema::table('letters', function ($table): void {
            $table->dropUnique('uq_letters_seq');
        });

        DB::statement('ALTER TABLE letters DROP COLUMN issued_year');

        DB::statement(
            'ALTER TABLE letters
             ADD COLUMN issued_year SMALLINT UNSIGNED NOT NULL
             COMMENT "Tahun menurut kalender desa (App\\\\Support\\\\VillageTime), bukan UTC"
             AFTER issued_at'
        );

        // Mengisi baris yang sudah ada, bila migrasi dijalankan di pemasangan
        // yang sudah menerbitkan surat.
        DB::statement('UPDATE letters SET issued_year = YEAR(issued_at) WHERE issued_year = 0');

        Schema::table('letters', function ($table): void {
            $table->unique(['letter_type_id', 'issued_year', 'sequence_number'], 'uq_letters_seq');
        });

        // Indeks penopang sementara tidak lagi diperlukan: uq_letters_seq
        // kembali menopang kunci asingnya.
        Schema::table('letters', function ($table): void {
            $table->dropIndex('idx_letters_type');
        });
    }

    public function down(): void
    {
        Schema::table('letters', function ($table): void {
            $table->index('letter_type_id', 'idx_letters_type');
        });

        Schema::table('letters', function ($table): void {
            $table->dropUnique('uq_letters_seq');
        });

        DB::statement('ALTER TABLE letters DROP COLUMN issued_year');
        DB::statement(
            'ALTER TABLE letters
             ADD COLUMN issued_year SMALLINT UNSIGNED
             AS (YEAR(issued_at)) STORED AFTER issued_at'
        );

        Schema::table('letters', function ($table): void {
            $table->unique(['letter_type_id', 'issued_year', 'sequence_number'], 'uq_letters_seq');
        });

        Schema::table('letters', function ($table): void {
            $table->dropIndex('idx_letters_type');
        });
    }
};
