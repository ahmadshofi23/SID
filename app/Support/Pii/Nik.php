<?php

declare(strict_types=1);

namespace App\Support\Pii;

use SensitiveParameter;

/**
 * Pemeriksaan & penyajian NIK — FR-PDK-02.
 *
 * Penting: hanya format 16 digit yang DITOLAK keras. Ketidaksesuaian kode
 * wilayah atau tanggal lahir hanya menghasilkan PERINGATAN, tidak menolak.
 * Alasannya: di lapangan banyak NIK sah yang tidak mengikuti pola (NIK lama,
 * hasil pemutakhiran Dukcapil, pindah domisili). Menolak keras akan membuat
 * petugas tidak dapat memasukkan warga yang nyata ada.
 */
final class Nik
{
    public const LENGTH = 16;

    public static function isWellFormed(#[SensitiveParameter] string $nik): bool
    {
        return (bool) preg_match('/^\d{16}$/', $nik);
    }

    public static function normalize(#[SensitiveParameter] string $nik): string
    {
        return preg_replace('/\D/', '', $nik) ?? '';
    }

    /**
     * Samarkan NIK: 3201234567890123 -> 3201••••••••0123
     * Empat digit pertama (kode provinsi/kabupaten) dan empat terakhir
     * dibiarkan agar petugas masih dapat membedakan dua warga, tanpa
     * mengungkap keseluruhan identitas (RULE-07).
     */
    public static function mask(#[SensitiveParameter] string $nik): string
    {
        if (! self::isWellFormed($nik)) {
            return str_repeat('•', 8);
        }

        return substr($nik, 0, 4).str_repeat('•', 8).substr($nik, -4);
    }

    /**
     * Peringatan (bukan galat) atas ketidakwajaran isi NIK.
     *
     * @return list<string>
     */
    public static function warnings(
        #[SensitiveParameter] string $nik,
        ?string $birthDate = null,
        ?string $gender = null,
    ): array {
        $warnings = [];

        if (! self::isWellFormed($nik)) {
            return ['NIK harus terdiri dari 16 angka.'];
        }

        // Digit 7-12 = tanggal lahir (DDMMYY); DD + 40 untuk perempuan.
        $day = (int) substr($nik, 6, 2);
        $month = (int) substr($nik, 8, 2);
        $year = (int) substr($nik, 10, 2);

        $isFemaleByNik = $day > 40;
        $realDay = $isFemaleByNik ? $day - 40 : $day;

        if ($realDay < 1 || $realDay > 31 || $month < 1 || $month > 12) {
            $warnings[] = 'Bagian tanggal lahir di dalam NIK tidak lazim. '
                .'Periksa kembali, namun NIK tetap dapat disimpan bila sudah sesuai KTP.';
        }

        if ($gender !== null) {
            $expected = $isFemaleByNik ? 'P' : 'L';

            if ($gender !== $expected) {
                $warnings[] = 'Jenis kelamin tidak sesuai pola NIK '
                    ."(NIK menunjukkan {$expected}). Periksa kembali.";
            }
        }

        if ($birthDate !== null && $realDay >= 1 && $realDay <= 31 && $month >= 1 && $month <= 12) {
            $nikDate = sprintf('%02d-%02d', $month, $realDay);
            $inputDate = date('m-d', strtotime($birthDate) ?: 0);
            $inputYear = (int) date('y', strtotime($birthDate) ?: 0);

            if ($nikDate !== $inputDate || $inputYear !== $year) {
                $warnings[] = 'Tanggal lahir tidak sesuai dengan yang tertera di NIK. '
                    .'Periksa kembali, namun dapat disimpan bila sudah sesuai dokumen.';
            }
        }

        return $warnings;
    }
}
