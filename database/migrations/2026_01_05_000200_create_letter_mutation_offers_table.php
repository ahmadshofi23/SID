<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tawaran mutasi dari surat SKL/SKM — Sprint 5, docs/13 §5.4 & §5.5, KEP-015.
 *
 * MENGAPA TAWARAN INI PERLU DISIMPAN, bukan dihitung saat layar dibuka.
 *
 * Keadaan "sudah dicatat" memang dapat diturunkan dari ada-tidaknya mutasi
 * yang menunjuk surat ini. Yang TIDAK dapat diturunkan adalah keadaan ketiga:
 * petugas sudah melihat tawarannya dan sengaja MENOLAK — karena kelahiran
 * sudah dicatat lewat jalur lain, atau karena bayinya lahir dan terdaftar di
 * desa lain. Tanpa baris ini, tawaran yang sudah ditolak akan muncul kembali
 * setiap kali layar dibuka, dan petugas belajar mengabaikannya — yang justru
 * menghapus manfaat tawaran itu.
 *
 * Satu tawaran per PENGAJUAN (indeks unik di bawah): satu pengajuan hanya
 * menghasilkan satu surat, dan satu surat hanya menawarkan satu mutasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_mutation_offers', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('request_id', 36);
            $t->char('letter_id', 36)->comment('Surat terbit yang memicu tawaran');

            $t->enum('mutation_type', ['lahir', 'mati'])
                ->comment('Jenis mutasi yang ditawarkan — sesuai jenis surat');
            $t->enum('status', ['menunggu', 'diterima', 'ditolak'])
                ->default('menunggu');

            /*
             * Hasil bila diterima. Nullable, dan TIDAK berupa kunci asing ke
             * resident_mutations: mutasi juga menyimpan letter_id-nya sendiri
             * (lihat migrasi 2026_01_02_000200), jadi tautannya sudah ada dari
             * kedua arah. Kolom ini ada agar layar surat dapat menautkan
             * langsung ke mutasi hasilnya tanpa memindai tabel mutasi.
             */
            $t->char('resident_mutation_id', 36)->nullable();

            $t->dateTime('decided_at')->nullable();
            $t->char('decided_by', 36)->nullable();
            $t->string('decided_by_name', 150)->nullable()
                ->comment('Disalin — tahan terhadap penghapusan akun');
            $t->string('decline_reason', 500)->nullable();

            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->unique('request_id', 'uq_lmo_request');
            $t->index(['status', 'mutation_type'], 'idx_lmo_status');

            $t->foreign('request_id', 'fk_lmo_request')
                ->references('id')->on('letter_requests')->cascadeOnDelete();

            /*
             * RESTRICT pada surat: surat terbit tidak pernah dihapus, hanya
             * dibatalkan (RULE-05). Bila suatu saat ada yang mencoba
             * menghapusnya, kegagalan di sini lebih baik daripada tawaran
             * yang menggantung tanpa surat asalnya.
             */
            $t->foreign('letter_id', 'fk_lmo_letter')
                ->references('id')->on('letters')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_mutation_offers');
    }
};
