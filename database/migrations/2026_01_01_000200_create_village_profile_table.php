<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Identitas desa — SATU BARIS saja (id = 1).
 *
 * Menggantikan tabel `tenants` dari rancangan multi-tenant (KEP-001).
 * CHECK (id = 1) adalah pengaman murah terhadap kesalahan konfigurasi yang
 * memasukkan desa kedua ke instalasi single-tenant ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('village_profile', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->string('code', 20)->comment('Kode desa Kemendagri');
            $t->string('name', 150);
            $t->string('district', 100)->comment('Kecamatan');
            $t->string('regency', 100)->comment('Kabupaten/Kota');
            $t->string('province', 100);
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 255)->nullable()->comment('Alamat kantor desa');
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('website', 150)->nullable();
            $t->string('logo_path', 255)->nullable();
            $t->string('letterhead_path', 255)->nullable();
            $t->string('stamp_path', 255)->nullable();
            $t->string('timezone', 40)->default('Asia/Jakarta');
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->text('history')->nullable();
            $t->text('vision')->nullable();
            $t->text('mission')->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        DB::statement(
            'ALTER TABLE village_profile
                ADD CONSTRAINT chk_village_single_row CHECK (id = 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('village_profile');
    }
};
