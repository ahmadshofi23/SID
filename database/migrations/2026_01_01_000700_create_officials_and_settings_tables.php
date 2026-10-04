<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Perangkat desa & pengaturan — docs/05-model-data.md §3.1, §3.6
 *
 * `officials` dipisah dari `users` karena:
 *   - tidak semua perangkat punya akun sistem;
 *   - nama perangkat yang sudah tidak menjabat masih tercantum di surat lama;
 *   - struktur organisasi perlu tampil di portal publik tanpa membuka tabel users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('officials', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('user_id', 36)->nullable();
            $t->string('name', 150);
            $t->string('position', 100)->comment('mis. "Kepala Desa"');
            $t->string('position_code', 40)->comment('kepala_desa, sekretaris_desa, ...');
            $t->string('nip', 30)->nullable();
            $t->string('niap', 30)->nullable()->comment('Nomor induk aparatur desa');
            $t->char('region_id', 36)->nullable()->comment('Untuk kepala dusun');
            $t->string('photo_path', 255)->nullable();
            $t->string('signature_path', 255)->nullable()
                ->comment('Gambar TTD untuk dicetak — BUKAN TTE tersertifikasi');
            $t->date('term_start')->nullable();
            $t->date('term_end')->nullable();
            $t->boolean('can_sign_letter')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->index(['is_active', 'sort_order'], 'idx_officials_active');
            $t->index(['can_sign_letter', 'is_active'], 'idx_officials_signer');

            $t->foreign('user_id', 'fk_officials_user')
                ->references('id')->on('users')->nullOnDelete();
            $t->foreign('region_id', 'fk_officials_region')
                ->references('id')->on('regions')->nullOnDelete();
        });

        Schema::create('settings', function (Blueprint $t): void {
            $t->string('key', 80)->primary();
            $t->json('value');
            $t->char('updated_by', 36)->nullable();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('officials');
    }
};
