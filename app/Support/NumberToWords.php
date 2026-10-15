<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Angka menjadi huruf dalam bahasa Indonesia.
 *
 * Dipakai pada surat keterangan penghasilan dan surat kuasa, yang menuliskan
 * nilai dalam angka DAN huruf — praktik baku dokumen resmi agar angka tidak
 * dapat diubah setelah surat ditandatangani.
 */
final class NumberToWords
{
    private const UNITS = [
        'nol', 'satu', 'dua', 'tiga', 'empat',
        'lima', 'enam', 'tujuh', 'delapan', 'sembilan',
    ];

    public static function rupiah(int $number): string
    {
        return trim(self::convert($number)).' rupiah';
    }

    public static function convert(int $number): string
    {
        if ($number < 0) {
            return 'minus '.self::convert(-$number);
        }

        if ($number < 10) {
            return self::UNITS[$number];
        }

        // "sebelas" s.d. "sembilan belas" — bentuk khusus, bukan pola umum.
        if ($number < 20) {
            return $number === 10
                ? 'sepuluh'
                : ($number === 11 ? 'sebelas' : self::UNITS[$number % 10].' belas');
        }

        if ($number < 100) {
            return trim(self::UNITS[intdiv($number, 10)].' puluh '.self::remainder($number % 10));
        }

        // "seratus", bukan "satu ratus" — berlaku juga untuk ribu dan sejenisnya.
        if ($number < 1000) {
            $prefix = intdiv($number, 100) === 1 ? 'seratus' : self::UNITS[intdiv($number, 100)].' ratus';

            return trim($prefix.' '.self::remainder($number % 100));
        }

        foreach ([
            1_000_000_000_000 => 'triliun',
            1_000_000_000 => 'miliar',
            1_000_000 => 'juta',
            1_000 => 'ribu',
        ] as $value => $name) {
            if ($number >= $value) {
                $count = intdiv($number, $value);
                $prefix = ($count === 1 && $value === 1_000) ? 'seribu' : self::convert($count).' '.$name;

                return trim($prefix.' '.self::remainder($number % $value));
            }
        }

        return (string) $number;
    }

    private static function remainder(int $value): string
    {
        return $value === 0 ? '' : self::convert($value);
    }
}
