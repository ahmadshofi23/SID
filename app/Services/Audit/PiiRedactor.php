<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Penyensoran data pribadi sebelum ditulis ke log — docs/08 §5.
 *
 * ATURAN MUTLAK: data kelas K1 (NIK, nomor KK) TIDAK PERNAH muncul di log
 * aplikasi, audit log, pesan galat, atau laporan crash. Kelas ini adalah
 * jaring pengaman otomatis agar kepatuhan itu tidak bergantung pada ingatan
 * tiap pengembang.
 */
final class PiiRedactor
{
    /** Nama field yang nilainya selalu disensor penuh. */
    private const SENSITIVE_KEYS = [
        'nik', 'nik_enc', 'nik_hash', 'nomor_kk', 'kk_number', 'kk_number_enc',
        'kk_number_hash', 'father_nik_hash', 'mother_nik_hash',
        'password', 'password_hash', 'password_confirmation',
        'two_factor_secret', 'two_factor_recovery_codes', 'token',
        'remember_token', 'api_key', 'secret',
    ];

    public const PLACEHOLDER = '[disensor]';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::PLACEHOLDER;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => $this->redact($value),
                is_string($value) => $this->redactString($value),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Sensor pola data pribadi di dalam teks bebas — jaring pengaman untuk
     * nilai yang lolos dari pemeriksaan nama field (mis. NIK yang tertulis di
     * dalam catatan petugas).
     */
    public function redactString(string $value): string
    {
        // NIK / nomor KK: tepat 16 digit berdiri sendiri
        $value = preg_replace('/\b\d{16}\b/', self::PLACEHOLDER, $value) ?? $value;

        // Nomor ponsel Indonesia
        $value = preg_replace(
            '/\b(?:\+?62|0)8\d{7,12}\b/', self::PLACEHOLDER, $value
        ) ?? $value;

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_ends_with($normalized, '_'.$sensitive)) {
                return true;
            }
        }

        return false;
    }
}
