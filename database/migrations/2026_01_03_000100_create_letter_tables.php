<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persuratan — docs/05-model-data.md §3.3, docs/13-katalog-surat.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------------------------- Jenis surat ---------------------------- */
        Schema::create('letter_types', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->string('code', 30);
            $t->string('name', 150);
            $t->string('title_on_letter', 150)->comment('Judul tercetak di surat');
            $t->string('classification_code', 20)->nullable()
                ->comment('Kode klasifikasi arsip — ambil dari arsip desa, jangan ditebak');
            $t->text('description')->nullable();

            $t->json('requirements')->default(new Expression('(JSON_ARRAY())'));
            $t->json('form_schema')->default(new Expression('(JSON_OBJECT())'))
                ->comment('Definisi isian dinamis — ADR-002');
            $t->mediumText('template_html')->comment('ISI POKOK saja, bukan surat utuh');

            $t->string('number_pattern', 150)
                ->default('{urut}/{kode}/{kode_desa}/{bulan_romawi}/{tahun}');
            $t->json('approval_flow')->default(new Expression('(JSON_ARRAY())'));

            $t->unsignedSmallInteger('sla_days')->default(1);
            $t->unsignedSmallInteger('validity_months')->nullable();
            $t->decimal('fee', 12, 2)->default(0);
            $t->boolean('requires_field_check')->default(false)
                ->comment('SKPT: wajib pengecekan lapangan');
            $t->text('warning_note')->nullable()
                ->comment('Peringatan di layar persetujuan');

            $t->boolean('is_public')->default(true)->comment('Dapat diajukan warga daring');
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);

            $t->char('created_by', 36)->nullable();
            $t->char('updated_by', 36)->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->dateTime('deleted_at')->nullable();

            $t->string('code_active', 30)
                ->storedAs('IF(deleted_at IS NULL, code, NULL)');
            $t->unique('code_active', 'uq_letter_types_code');
            $t->index(['is_active', 'sort_order'], 'idx_letter_types_active');
        });

        /* ------------------------- Penghitung nomor -------------------------
         | ADR-001. Titik serialisasi penomoran surat.
         |
         | letter_type_id bernilai '-' bila desa memakai pola nomor tunggal
         | untuk semua jenis surat (docs/13 §3 pola C).
         */
        Schema::create('letter_counters', function (Blueprint $t): void {
            $t->char('letter_type_id', 36);
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number')->default(0);
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->primary(['letter_type_id', 'year']);
        });

        /* ------------------------- Pengajuan surat --------------------------- */
        Schema::create('letter_requests', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('letter_type_id', 36);
            $t->string('ticket_number', 40);
            $t->char('resident_id', 36)->comment('Pemohon');
            $t->char('requested_by', 36)->nullable()->comment('Akun pengaju');
            $t->enum('channel', ['online', 'walk_in'])->default('online');
            $t->string('purpose', 255)->nullable();
            $t->json('form_data')->default(new Expression('(JSON_OBJECT())'));

            $t->enum('status', [
                'draft', 'diajukan', 'perlu_revisi', 'diverifikasi',
                'diterbitkan', 'ditolak', 'dibatalkan',
            ])->default('draft');
            $t->unsignedSmallInteger('current_step')->default(0);

            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('due_at')->nullable()->comment('Dari sla_days, hari kerja');
            $t->dateTime('completed_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->text('revision_note')->nullable();

            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->unique('ticket_number', 'uq_lr_ticket');
            $t->index(['status', 'submitted_at'], 'idx_lr_status');
            $t->index(['resident_id', 'created_at'], 'idx_lr_resident');
            $t->index(['letter_type_id', 'status'], 'idx_lr_type');
            $t->index(['status', 'due_at'], 'idx_lr_due');
            $t->index(['requested_by', 'created_at'], 'idx_lr_requester');

            $t->foreign('letter_type_id', 'fk_lr_type')
                ->references('id')->on('letter_types')->restrictOnDelete();
            $t->foreign('resident_id', 'fk_lr_resident')
                ->references('id')->on('residents')->restrictOnDelete();
        });

        /* ------------------------ Riwayat status ---------------------------- */
        Schema::create('letter_request_logs', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('request_id', 36);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->unsignedSmallInteger('step')->nullable();
            $t->char('actor_id', 36)->nullable();
            $t->string('actor_name', 150)->nullable()
                ->comment('Disalin — tahan terhadap penghapusan akun');
            $t->text('note')->nullable();
            $t->dateTime('created_at')->useCurrent();

            $t->index(['request_id', 'created_at'], 'idx_lrl_request');
            $t->foreign('request_id', 'fk_lrl_request')
                ->references('id')->on('letter_requests')->cascadeOnDelete();
        });

        /* --------------------------- Surat terbit ---------------------------
         | ADR-003. Snapshot permanen & tidak diubah.
         */
        Schema::create('letters', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('request_id', 36);
            $t->char('letter_type_id', 36);
            $t->string('letter_number', 100);
            $t->unsignedInteger('sequence_number');
            $t->dateTime('issued_at');
            $t->unsignedSmallInteger('issued_year')
                ->storedAs('YEAR(issued_at)');
            $t->char('issued_by', 36)->comment('Akun yang menyetujui');
            $t->char('signer_official_id', 36)->nullable();
            $t->string('signer_name', 150)->comment('Disalin saat terbit');
            $t->string('signer_position', 100);
            $t->date('valid_until')->nullable();

            $t->json('snapshot')->comment('Data pemohon SAAT terbit — prinsip docs/05 §1.6');
            $t->mediumText('rendered_html');
            $t->string('pdf_path', 255)->nullable()->comment('Diisi Sprint 5');
            $t->char('pdf_hash', 64)->nullable();
            $t->string('verification_code', 32);

            $t->enum('status', ['sah', 'dibatalkan'])->default('sah');
            $t->dateTime('cancelled_at')->nullable();
            $t->char('cancelled_by', 36)->nullable();
            $t->text('cancel_reason')->nullable();
            $t->char('replaces_letter_id', 36)->nullable()->comment('RULE-05');
            $t->unsignedSmallInteger('print_count')->default(0);
            $t->dateTime('created_at')->useCurrent();

            $t->unique('letter_number', 'uq_letters_number');
            $t->unique('verification_code', 'uq_letters_verif');

            /*
             * JARING PENGAMAN TERAKHIR penomoran surat.
             *
             * Walau logika di LetterNumberService keliru, basis data menolak
             * nomor urut ganda untuk jenis surat & tahun yang sama. Nomor
             * ganda adalah temuan audit administrasi desa — ia tidak boleh
             * bergantung pada satu lapis saja.
             */
            $t->unique(['letter_type_id', 'issued_year', 'sequence_number'], 'uq_letters_seq');

            $t->index('issued_at', 'idx_letters_issued');
            $t->index('request_id', 'idx_letters_request');

            $t->foreign('request_id', 'fk_letters_request')
                ->references('id')->on('letter_requests')->restrictOnDelete();
            $t->foreign('letter_type_id', 'fk_letters_type')
                ->references('id')->on('letter_types')->restrictOnDelete();
            $t->foreign('replaces_letter_id', 'fk_letters_replaces')
                ->references('id')->on('letters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letters');
        Schema::dropIfExists('letter_request_logs');
        Schema::dropIfExists('letter_requests');
        Schema::dropIfExists('letter_counters');
        Schema::dropIfExists('letter_types');
    }
};
