<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Pii\Nik;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validasi format NIK — FR-PDK-02.
 *
 * HANYA format 16 digit yang ditolak. Ketidaksesuaian kode wilayah atau
 * tanggal lahir di dalam NIK hanya menghasilkan peringatan (lihat
 * App\Support\Pii\Nik::warnings) dan tidak boleh memblokir penyimpanan:
 * di lapangan banyak NIK sah yang tidak mengikuti pola.
 */
final class NikRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = Nik::normalize((string) $value);

        if ($normalized === '') {
            $fail('NIK wajib diisi.');

            return;
        }

        if (! Nik::isWellFormed($normalized)) {
            $fail(sprintf(
                'NIK harus terdiri dari 16 angka. Anda memasukkan %d angka.',
                mb_strlen($normalized)
            ));
        }
    }
}
