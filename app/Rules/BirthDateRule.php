<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * Validasi tanggal lahir — FR-PDK-02.
 *
 * Menggantikan CHECK constraint yang TIDAK DAPAT dibuat di basis data:
 * MySQL dan PostgreSQL sama-sama melarang fungsi non-deterministik
 * (CURRENT_DATE) di dalam CHECK, karena baris yang sah hari ini bisa menjadi
 * tidak sah besok. Lihat database/migrations/..._families_and_residents.
 */
final class BirthDateRule implements ValidationRule
{
    private const MAX_AGE_YEARS = 120;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $date = Carbon::parse((string) $value);
        } catch (\Throwable) {
            $fail('Tanggal lahir tidak dapat dibaca. Gunakan format tanggal yang benar.');

            return;
        }

        if ($date->isAfter(now()->endOfDay())) {
            $fail('Tanggal lahir tidak boleh melebihi hari ini.');

            return;
        }

        if ($date->diffInYears(now()) > self::MAX_AGE_YEARS) {
            $fail(sprintf(
                'Tanggal lahir menghasilkan usia lebih dari %d tahun. Periksa kembali — kemungkinan salah ketik tahun.',
                self::MAX_AGE_YEARS
            ));
        }
    }
}
