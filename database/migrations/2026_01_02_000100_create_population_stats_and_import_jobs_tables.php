<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — statistik agregat (ADR-007) & pekerjaan impor (FR-PDK-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Tabel ringkasan statistik — pengganti MATERIALIZED VIEW yang tidak
         * ada di MySQL (docs/05-model-data.md §4).
         *
         * Halaman statistik publik membaca DARI SINI, tidak pernah dari tabel
         * `residents`. Selain lebih cepat, ini memperkecil permukaan kebocoran:
         * endpoint publik tidak punya jalan menuju data pribadi sama sekali.
         */
        Schema::create('population_stats', function (Blueprint $t): void {
            $t->id();
            $t->char('region_id', 36)->nullable()->comment('NULL = seluruh desa');
            $t->enum('gender', ['L', 'P'])->nullable();
            $t->string('age_group', 10)->nullable();
            $t->string('religion_code', 20)->nullable();
            $t->string('education_code', 20)->nullable();
            $t->string('occupation_code', 30)->nullable();
            $t->string('marital_status_code', 20)->nullable();
            $t->unsignedInteger('total');
            $t->dateTime('computed_at');

            /*
             * Kolom pembeda dipakai sebagai kunci unik agar penyegaran dapat
             * memakai "upsert". Kolom nullable tidak dapat menjadi kunci unik
             * yang andal di MySQL (NULL tidak sama dengan NULL), karena itu
             * dipakai kolom hash gabungan.
             */
            $t->char('dimension_hash', 64);
            $t->unique('dimension_hash', 'uq_pop_stats_dimension');
            $t->index('region_id', 'idx_pop_stats_region');
            $t->index(['gender', 'age_group'], 'idx_pop_stats_demografi');
        });

        /*
         * Pekerjaan impor diproses ASINKRON (UC-04 langkah 3).
         *
         * Impor 20.000 baris tidak boleh berjalan di dalam permintaan HTTP:
         * petugas akan menunggu layar kosong, dan satu timeout membatalkan
         * seluruh pekerjaan tanpa jejak.
         */
        Schema::create('import_jobs', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->string('type', 40)->comment('residents, families, ...');
            $t->string('original_name', 255);
            $t->string('storage_path', 500);
            $t->enum('status', [
                'menunggu', 'pra_validasi', 'ditinjau', 'diproses', 'selesai', 'gagal', 'dibatalkan',
            ])->default('menunggu');

            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('invalid_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('updated_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);

            /*
             * Galat per baris disimpan sebagai JSON, BUKAN sebagai berkas unduhan
             * berisi data mentah. Berkas impor memuat NIK; menyimpan salinannya
             * untuk diunduh ulang berarti menggandakan tempat kebocoran.
             * Yang disimpan hanya nomor baris, nama kolom, dan sebab galat.
             */
            $t->json('errors')->nullable();
            $t->text('failure_reason')->nullable();

            $t->char('created_by', 36)->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->dateTime('finished_at')->nullable();

            $t->index(['status', 'created_at'], 'idx_import_status');
            $t->index('created_by', 'idx_import_creator');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('population_stats');
    }
};
