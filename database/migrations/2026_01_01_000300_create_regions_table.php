<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Hierarki wilayah: Dusun -> RW -> RT (rekursif, self-referencing).
 *
 * Kolom `path` adalah materialized path (mis. 'krajan/rw02/rt003') sehingga
 * "semua penduduk satu dusun" dapat dicari dengan LIKE 'krajan/%' memakai
 * indeks, tanpa CTE rekursif. Dihitung ulang aplikasi saat wilayah dipindah.
 *
 * PENTING: kolom ini juga menjadi dasar PEMBATASAN AKSES Kepala Dusun
 * (docs/04-arsitektur.md §4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('parent_id', 36)->nullable();
            $t->enum('level', ['dusun', 'rw', 'rt']);
            $t->string('code', 20);
            $t->string('name', 100);
            $t->string('head_name', 150)->nullable();
            $t->string('head_phone', 30)->nullable();
            $t->string('path', 255);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->unique(['parent_id', 'code'], 'uq_regions_code');
            $t->index('level', 'idx_regions_level');
            $t->index('path', 'idx_regions_path');

            $t->foreign('parent_id', 'fk_regions_parent')
                ->references('id')->on('regions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
