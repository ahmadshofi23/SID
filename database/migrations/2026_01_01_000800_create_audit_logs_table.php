<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Audit log — ADR-005, docs/05-model-data.md §3.6, FR-ADM-04, RULE-12
 *
 * Tiga keputusan sadar:
 *  1. PK BIGINT AUTO_INCREMENT, bukan UUID — tabel ini paling banyak barisnya
 *     dan tidak pernah dirujuk dari luar; PK berurutan jauh lebih efisien
 *     untuk penulisan InnoDB (8 byte vs 36).
 *  2. Tanpa partisi — ± 20.000 baris/bulan pada satu desa masih nyaman tanpa
 *     partisi (docs/05 §8). Pengarsipan baris > 5 tahun ditangani job.
 *  3. Tanpa foreign key ke users — log harus tetap utuh walau akun dihapus,
 *     karena itu actor_name DISALIN, bukan di-join.
 *
 * Sifat append-only ditegakkan di tingkat hak akses MySQL:
 *   php artisan sid:harden-audit-log
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->char('actor_id', 36)->nullable();
            $t->string('actor_name', 150)->nullable();
            $t->string('actor_roles', 255)->nullable()
                ->comment('mis. "admin_desa,kasi_pemerintahan"');
            $t->string('action', 60)->comment('resident.updated, letter.issued, ...');
            $t->string('entity_type', 60)->nullable();
            $t->char('entity_id', 36)->nullable();
            $t->json('changes')->nullable()
                ->comment('Nilai lama/baru — data pribadi WAJIB ter-masking');
            $t->json('context')->nullable()->comment('{"rows":1500,"filter":{...}}');
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 300)->nullable();
            $t->char('correlation_id', 36)->nullable();
            $t->char('prev_hash', 64)->nullable();
            $t->char('row_hash', 64)->comment('Hash chain — deteksi perubahan di luar aplikasi');
            $t->dateTime('created_at', 3)->useCurrent();

            $t->index(['entity_type', 'entity_id', 'created_at'], 'idx_audit_entity');
            $t->index(['actor_id', 'created_at'], 'idx_audit_actor');
            $t->index(['action', 'created_at'], 'idx_audit_action');
            $t->index('created_at', 'idx_audit_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
