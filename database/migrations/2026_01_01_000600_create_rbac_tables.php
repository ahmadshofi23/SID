<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * RBAC — docs/05-model-data.md §3.4, matriks hak akses docs/03-srs.md §4.9
 *
 * user_roles.region_id adalah SATU-SATUNYA sumber pembatasan wilayah untuk
 * Kepala Dusun. Tanpa Row-Level Security (MySQL, KEP-002), tidak ada jaring
 * pengaman basis data bila kode lupa menerapkannya — lihat lapis repository
 * di app/Repositories dan uji di tests/Authorization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->string('code', 40);
            $t->string('name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_system')->default(false)
                ->comment('Peran sistem tidak dapat dihapus');

            $t->unique('code', 'uq_roles_code');
        });

        Schema::create('permissions', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->string('code', 60)->comment("mis. 'penduduk.lihat'");
            $t->string('module', 40);
            $t->string('name', 120);

            $t->unique('code', 'uq_permissions_code');
            $t->index('module');
        });

        Schema::create('role_permissions', function (Blueprint $t): void {
            $t->char('role_id', 36);
            $t->char('permission_id', 36);

            $t->primary(['role_id', 'permission_id']);
            $t->foreign('role_id', 'fk_rp_role')
                ->references('id')->on('roles')->cascadeOnDelete();
            $t->foreign('permission_id', 'fk_rp_perm')
                ->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('user_roles', function (Blueprint $t): void {
            $t->char('user_id', 36);
            $t->char('role_id', 36);
            $t->char('region_id', 36)->nullable()
                ->comment('Pembatasan wilayah: Kepala Dusun / RT');

            $t->primary(['user_id', 'role_id']);
            $t->index('region_id', 'idx_ur_region');

            $t->foreign('user_id', 'fk_ur_user')
                ->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('role_id', 'fk_ur_role')
                ->references('id')->on('roles')->cascadeOnDelete();
            $t->foreign('region_id', 'fk_ur_region')
                ->references('id')->on('regions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
