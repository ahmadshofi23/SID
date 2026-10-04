<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pembaca tabel referensi (FR-PDK-03, FR-ADM-09).
 *
 * Di-cache karena isinya hampir tidak pernah berubah tetapi dibaca di setiap
 * formulir dan setiap baris daftar penduduk. Tanpa cache, satu halaman daftar
 * penduduk akan menghasilkan belasan kueri referensi.
 */
final class ReferenceData
{
    private const TABLES = [
        'religions' => 'ref_religions',
        'marital_statuses' => 'ref_marital_statuses',
        'family_relations' => 'ref_family_relations',
        'educations' => 'ref_educations',
        'occupations' => 'ref_occupations',
        'blood_types' => 'ref_blood_types',
        'disabilities' => 'ref_disabilities',
        'welfare_statuses' => 'ref_welfare_statuses',
        'house_ownerships' => 'ref_house_ownerships',
        'complaint_categories' => 'ref_complaint_categories',
        'post_categories' => 'ref_post_categories',
        'asset_categories' => 'ref_asset_categories',
    ];

    /**
     * Satu jenis referensi sebagai kode => nama.
     *
     * @return array<string, string>
     */
    public static function get(string $type): array
    {
        $table = self::TABLES[$type] ?? null;

        if ($table === null) {
            return [];
        }

        /** @var array<string, string> $items */
        $items = Cache::remember(
            "ref.{$type}",
            now()->addDay(),
            fn (): array => DB::table($table)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->pluck('name', 'code')
                ->all()
        );

        return $items;
    }

    /**
     * Seluruh referensi, untuk menerjemahkan kode menjadi label saat menampilkan.
     *
     * @return array<string, array<string, string>>
     */
    public static function labels(): array
    {
        $all = [];

        foreach (array_keys(self::TABLES) as $type) {
            $all[$type] = self::get($type);
        }

        return $all;
    }

    /** @return array<string, array<string, string>> */
    public static function forResidentForm(): array
    {
        return [
            'religions' => self::get('religions'),
            'marital_statuses' => self::get('marital_statuses'),
            'family_relations' => self::get('family_relations'),
            'educations' => self::get('educations'),
            'occupations' => self::get('occupations'),
            'blood_types' => self::get('blood_types'),
            'disabilities' => self::get('disabilities'),
        ];
    }

    /** @return array<string, array<string, string>> */
    public static function forResidentFilters(): array
    {
        return [
            'religions' => self::get('religions'),
            'educations' => self::get('educations'),
            'occupations' => self::get('occupations'),
        ];
    }

    /** @return array<string, array<string, string>> */
    public static function forFamilyForm(): array
    {
        return [
            'welfare_statuses' => self::get('welfare_statuses'),
            'house_ownerships' => self::get('house_ownerships'),
        ];
    }

    public static function forget(): void
    {
        foreach (array_keys(self::TABLES) as $type) {
            Cache::forget("ref.{$type}");
        }
    }
}
