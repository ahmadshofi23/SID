<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalisasi nama untuk pencarian (docs/05-model-data.md §3.2).
 *
 * Karena MySQL tidak punya pg_trgm (KEP-002), pencarian nama bergantung pada
 * kolom ternormalisasi + LIKE. Normalisasi menutup sebagian besar penyebab
 * "warga tidak ditemukan": gelar, tanda baca, dan spasi ganda.
 */
final class NameNormalizer
{
    /** Gelar & sapaan yang dibuang dari kolom pencarian. */
    private const NOISE = [
        'h.', 'hj.', 'drs.', 'dra.', 'ir.', 'dr.', 'prof.', 'spd.', 's.pd',
        's.e', 's.h', 's.t', 's.sos', 'm.pd', 'm.si', 'a.md', 'st.', 'se.',
        'bapak', 'ibu', 'bpk', 'sdr.', 'sdri.', 'alm.', 'almh.',
    ];

    public static function normalize(string $name): string
    {
        $value = mb_strtolower(trim($name));

        foreach (self::NOISE as $noise) {
            $value = str_replace($noise.' ', ' ', $value);
            if (str_ends_with($value, ' '.$noise)) {
                $value = substr($value, 0, -strlen($noise) - 1);
            }
        }

        // Buang tanda baca, pertahankan huruf/angka/spasi (termasuk apostrof
        // pada nama seperti Ma'ruf -> maruf agar pencarian tetap cocok).
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }
}
