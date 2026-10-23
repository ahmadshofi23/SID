<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks penutup untuk halaman Laporan — KEP-019, FR-LAP-03, NFR-PERF-01.
 *
 * `idx_residents_status` dilebarkan dari (status, deleted_at) menjadi
 * (status, deleted_at, region_id, gender, religion_code, education_code,
 *  occupation_code, marital_status_code).
 *
 * MENGAPA, DENGAN ANGKA (uji beban Sprint 7, docs/22-uji-beban-sprint-7.md §7)
 * --------------------------------------------------------------------------
 * Halaman Laporan menampilkan rekap penduduk per wilayah dan per lima
 * kategori. Seluruhnya menyaring `status = 'aktif' AND deleted_at IS NULL`,
 * lalu membaca satu kolom lain per baris. Dengan indeks lama yang hanya
 * memuat dua kolom penyaring, setiap rekap harus mengambil kolomnya dari
 * indeks berkluster — 19.600 pencarian baris acak per rekap. Pada 20.000
 * penduduk terukur:
 *
 *   rekap per wilayah       370 ms  ->   10 ms
 *   rekap lima kategori     385 ms  ->   22 ms
 *   ReportService::population()  755 ms  ->   43 ms
 *   halaman /laporan (waktu DB)  495 ms  ->   66 ms
 *
 * Angka 755 ms itu MELEWATI NFR-PERF-01 (p95 baca ≤ 500 ms) sendirian, pada
 * volume yang justru menjadi sasaran resmi proyek ini (NFR-PERF-07). Halaman
 * itu lolos sampai Sprint 7 hanya karena basis data pengembangan berisi 500
 * penduduk, bukan 20.000.
 *
 * MENGAPA MELEBARKAN INDEKS YANG ADA DAN BUKAN MENAMBAH INDEKS BARU
 * -----------------------------------------------------------------
 * Indeks penutup TERPISAH sudah dicoba lebih dulu, dan ditolak karena diukur:
 * pengoptimasi MySQL tetap memilih `idx_residents_status` yang lebih sempit
 * (biaya perkiraannya lebih rendah), sehingga indeks baru itu hanya terpakai
 * dengan `FORCE INDEX`. Petunjuk indeks di dalam Eloquent adalah janji yang
 * patah tanpa suara pada versi MySQL berikutnya, dan kegagalannya berupa
 * halaman yang perlahan melambat — kelas bug yang tidak pernah terlihat di CI.
 * Dengan melebarkan indeks yang SUDAH dipilih, tidak ada pilihan yang perlu
 * dipaksakan.
 *
 * HARGA YANG DIBAYAR, DAN MENGAPA SEBANDING
 * -----------------------------------------
 * Enam kolom tambahan (± 110 byte per baris) dibayar pada setiap penyisipan
 * dan setiap perubahan salah satu kolom itu. Di satu desa, perubahan data
 * penduduk berjumlah puluhan per hari; halaman Laporan dibuka beberapa kali
 * sehari oleh orang yang menunggu di depan layar. Yang ditukar adalah
 * penulisan yang tidak ada yang menungguinya dengan pembacaan yang
 * ditunggui.
 *
 * KONSEKUENSI DOKUMEN: docs/05-model-data.md §3.2 masih menuliskan indeks
 * yang lama. Sampai dokumen itu diselaraskan, KEP-019 adalah sumber
 * kebenarannya.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'status', 'deleted_at', 'region_id', 'gender',
        'religion_code', 'education_code', 'occupation_code', 'marital_status_code',
    ];

    public function up(): void
    {
        Schema::table('residents', function (Blueprint $t): void {
            /*
             * Dilepas lalu dibuat ulang DENGAN NAMA YANG SAMA.
             *
             * Nama yang sama disengaja: seluruh kueri yang sudah memakainya
             * (penyaringan status di daftar penduduk, statistik, Buku Induk)
             * tetap memakai indeks yang sama persis dengan dua kolom pertama
             * yang sama persis, sehingga tidak ada satu pun rencana kueri lain
             * yang berubah. Nama baru akan meninggalkan dua indeks dengan
             * awalan identik — yang kedua tidak pernah terpakai dan tetap
             * dibayar pada setiap penulisan.
             */
            $t->dropIndex('idx_residents_status');
            $t->index(self::COLUMNS, 'idx_residents_status');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $t): void {
            $t->dropIndex('idx_residents_status');
            $t->index(['status', 'deleted_at'], 'idx_residents_status');
        });
    }
};
