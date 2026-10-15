<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Penyamaran nama warga untuk halaman yang dapat dibuka pihak luar.
 *
 * Dipakai dua halaman publik — verifikasi keaslian surat (FR-SRT-14) dan
 * pelacakan status pengajuan (FR-SRT-13). Dijadikan satu kelas karena
 * keduanya harus menyamarkan dengan cara yang SAMA: dua aturan penyamaran
 * yang sedikit berbeda membuat satu halaman membocorkan lebih banyak
 * daripada yang lain tanpa ada yang menyadarinya.
 */
final class NameMask
{
    /**
     * "SITI AMINAH RAHAYU" menjadi "SITI A***** R*****".
     *
     * Suku kata pertama dibiarkan utuh agar pembaca dapat mencocokkannya
     * dengan surat atau tanda terima yang ia pegang — itulah seluruh gunanya.
     */
    public static function partial(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if ($parts === ['']) {
            return '';
        }

        if (count($parts) === 1) {
            return Str::substr($parts[0], 0, 2).str_repeat('*', max(1, Str::length($parts[0]) - 2));
        }

        $masked = array_map(
            fn (string $part, int $i): string => $i === 0
                ? $part
                : Str::substr($part, 0, 1).str_repeat('*', max(1, Str::length($part) - 1)),
            $parts,
            array_keys($parts),
        );

        return implode(' ', $masked);
    }
}
