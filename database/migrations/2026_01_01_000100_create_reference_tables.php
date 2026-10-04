<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tabel referensi (docs/05-model-data.md §3.7).
 *
 * `code` dipakai sebagai primary key (bukan id numerik) agar nilai tetap
 * terbaca manusia di ekspor Excel, laporan, dan API tanpa perlu JOIN.
 */
return new class extends Migration
{
    private const TABLES = [
        'ref_religions',
        'ref_marital_statuses',
        'ref_family_relations',
        'ref_educations',
        'ref_occupations',
        'ref_blood_types',
        'ref_disabilities',
        'ref_welfare_statuses',
        'ref_house_ownerships',
        'ref_complaint_categories',
        'ref_post_categories',
        'ref_asset_categories',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->string('code', 30)->primary();
                $t->string('name', 150);
                $t->string('group', 40)->nullable();
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);

                $t->index(['is_active', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
