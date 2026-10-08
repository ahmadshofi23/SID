<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi penduduk — docs/05-model-data.md §3.2, FR-PDK-10, BR-03.
 *
 * Tabel ini sudah dirancang sejak dokumen 05 tetapi migrasinya terlewat pada
 * Sprint 1; baru dibuat saat modulnya dibangun di Sprint 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_mutations', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('resident_id', 36);
            $t->enum('type', [
                'lahir', 'mati', 'pindah_datang', 'pindah_keluar', 'perubahan_data',
            ]);
            $t->date('mutation_date');
            $t->text('reason')->nullable();

            // Kelahiran
            $t->decimal('birth_weight', 5, 2)->nullable()->comment('kg');
            $t->decimal('birth_length', 5, 2)->nullable()->comment('cm');
            $t->string('birth_helper', 50)->nullable();

            // Kematian
            $t->string('death_cause', 100)->nullable();
            $t->string('death_place', 150)->nullable();

            // Perpindahan
            $t->string('origin_address', 255)->nullable();
            $t->string('destination_address', 255)->nullable();

            // Perubahan data: simpan selisih nilai
            $t->json('changes')->nullable();

            $t->string('document_no', 100)->nullable()->comment('No. surat/akta pendukung');
            $t->char('letter_id', 36)->nullable()
                ->comment('Surat yang memicu mutasi — integrasi Sprint 4/5');
            $t->string('reported_by', 150)->nullable();
            $t->char('created_by', 36);
            $t->dateTime('created_at')->useCurrent();

            $t->index('mutation_date', 'idx_mutations_date');
            $t->index(['resident_id', 'mutation_date'], 'idx_mutations_resident');
            $t->index(['type', 'mutation_date'], 'idx_mutations_type');

            /*
             * RESTRICT: mutasi adalah catatan historis. Menghapus penduduk
             * tidak boleh menghapus jejak kelahiran atau kematiannya — itu
             * justru data yang paling perlu dipertahankan untuk laporan
             * kependudukan tahun-tahun sebelumnya.
             */
            $t->foreign('resident_id', 'fk_mutations_resident')
                ->references('id')->on('residents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_mutations');
    }
};
