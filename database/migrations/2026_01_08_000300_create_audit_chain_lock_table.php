<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Baris mutex perangkaian audit log — KEP-020.
 *
 * Tabel ini TIDAK menyimpan data. Ia berisi tepat satu baris yang tidak pernah
 * dibaca nilainya, dan satu-satunya gunanya adalah menjadi benda yang dikunci.
 *
 * MENGAPA IA ADA. AuditLogger merantai setiap baris baru ke baris sebelumnya
 * dengan `ORDER BY id DESC LIMIT 1 FOR UPDATE`. Pada InnoDB, kunci itu bukan
 * kunci baris melainkan next-key lock di UJUNG indeks — ia mencakup celah
 * sesudah baris terakhir. Dua transaksi yang melakukannya bersamaan sama-sama
 * memegang celah itu, lalu keduanya hendak MENYISIPKAN ke dalamnya; kunci
 * insert-intention yang diperlukan untuk itu bertentangan dengan gap lock
 * transaksi lain, dan MySQL membunuh salah satunya dengan galat 1213. Tidak
 * perlu sumber daya ketiga: dua penulisan audit saja sudah cukup. Uji beban
 * Sprint 7 menangkapnya satu kali dalam ± 44 menit pada jalur login, dan bagi
 * petugas yang mengalaminya ia berupa HTTP 500 (docs/23 §7 butir 1).
 *
 * Mengunci SATU baris ber-kunci primer lebih dulu menghapus penyebabnya, bukan
 * menyembunyikan gejalanya: kunci baris pada pencarian kesetaraan PK tidak
 * mengambil gap lock apa pun, sehingga dua penulisan audit tidak lagi dapat
 * saling menunggu — yang kedua hanya MENUNGGU yang pertama selesai. Deadlock
 * (dibatalkan) berubah menjadi lock wait (ditunggu), dan urutan serialisasi
 * yang dituntut ADR-005 justru menjadi eksplisit, bukan hilang.
 *
 * Yang SENGAJA tidak dilakukan di sini: tabel ini tidak menyimpan hash ujung
 * rantai. Menyimpannya akan membuat dua sumber kebenaran untuk pertanyaan
 * "baris terakhir apa" — dan sumber kedua itu akan basi setiap kali ada yang
 * menyisipkan ke audit_logs tanpa melewati AuditLogger (penyemai volume
 * melakukan tepat itu). Ujung rantai tetap dibaca dari audit_logs; tabel ini
 * hanya memastikan tidak ada dua pembaca yang melakukannya bersamaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_lock', function (Blueprint $t): void {
            /*
             * Tanpa AUTO_INCREMENT dan tanpa kolom lain. Barisnya selalu id=1;
             * nilai apa pun di sini tidak akan pernah dibaca.
             */
            $t->unsignedTinyInteger('id')->primary();
        });

        /*
         * JEBAKAN YANG SAMA SEPERTI letter_counters — jangan hapus penyisipan
         * ini. `SELECT ... FOR UPDATE` pada baris yang BELUM ADA tidak mengunci
         * apa pun di MySQL: ia hanya mengembalikan nol baris. Tanpa baris ini,
         * mutex di atas berubah menjadi tidak ada sama sekali, dan tidak ada
         * satu pun galat yang memberi tahu — perangkaian audit kembali
         * bersamaan, persis seperti sebelum KEP-020.
         */
        DB::statement('INSERT INTO audit_chain_lock (id) VALUES (1)
                       ON DUPLICATE KEY UPDATE id = id');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_lock');
    }
};
