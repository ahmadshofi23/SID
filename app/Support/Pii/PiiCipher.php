<?php

declare(strict_types=1);

namespace App\Support\Pii;

use RuntimeException;
use SensitiveParameter;

/**
 * Enkripsi & hashing data pribadi — ADR-004 (docs/04-arsitektur.md §5).
 *
 * Tiga nilai disimpan untuk setiap NIK / nomor KK:
 *   1. *_enc    AES-256-GCM  → nilai sebenarnya, hanya dapat dibaca aplikasi
 *   2. *_hash   HMAC-SHA256  → untuk pencarian PERSIS & penegakan keunikan
 *   3. *_masked teks         → untuk ditampilkan tanpa mengungkap data
 *
 * Mengapa HMAC dan bukan hash biasa: NIK hanya 16 digit. Tanpa pepper rahasia,
 * seluruh ruang NIK dapat di-brute force dalam hitungan menit, sehingga hash
 * biasa sama sekali tidak melindungi bila basis data bocor.
 *
 * Mengapa enkripsi di aplikasi dan bukan AES_ENCRYPT() MySQL: fungsi bawaan
 * menaruh kunci di dalam teks kueri, sehingga bocor ke slow query log, binlog,
 * dan SHOW PROCESSLIST.
 */
final class PiiCipher
{
    private const CIPHER = 'aes-256-gcm';

    private const TAG_LENGTH = 16;

    private string $key;

    private string $pepper;

    public function __construct(?string $key = null, ?string $pepper = null)
    {
        $key ??= (string) config('sid.pii.key');
        $pepper ??= (string) config('sid.pii.pepper');

        if ($key === '' || $pepper === '') {
            throw new RuntimeException(
                'PII_ENCRYPTION_KEY dan PII_HASH_PEPPER wajib diisi. '
                .'Jalankan: php artisan sid:generate-pii-keys'
            );
        }

        $this->key = $this->decodeKey($key);
        $this->pepper = $pepper;

        if (strlen($this->key) !== 32) {
            throw new RuntimeException(
                'PII_ENCRYPTION_KEY harus 32 byte (256 bit) setelah di-decode.'
            );
        }
    }

    /**
     * Enkripsi nilai. Keluaran: nonce(12) + tag(16) + ciphertext.
     */
    public function encrypt(#[SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(openssl_cipher_iv_length(self::CIPHER) ?: 12);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LENGTH
        );

        if ($cipherText === false) {
            throw new RuntimeException('Enkripsi data pribadi gagal.');
        }

        return $nonce.$tag.$cipherText;
    }

    public function decrypt(#[SensitiveParameter] string $payload): string
    {
        $nonceLength = openssl_cipher_iv_length(self::CIPHER) ?: 12;

        if (strlen($payload) <= $nonceLength + self::TAG_LENGTH) {
            throw new RuntimeException('Data terenkripsi tidak valid atau terpotong.');
        }

        $nonce = substr($payload, 0, $nonceLength);
        $tag = substr($payload, $nonceLength, self::TAG_LENGTH);
        $cipherText = substr($payload, $nonceLength + self::TAG_LENGTH);

        $plain = openssl_decrypt(
            $cipherText, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag
        );

        if ($plain === false) {
            // Gagal autentikasi GCM = data diubah, atau kunci salah/sudah dirotasi.
            throw new RuntimeException(
                'Dekripsi data pribadi gagal: data berubah atau kunci tidak sesuai.'
            );
        }

        return $plain;
    }

    /**
     * HMAC untuk pencarian persis & indeks keunikan. Deterministik.
     */
    public function hash(#[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, $this->pepper);
    }

    private function decodeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('PII_ENCRYPTION_KEY bukan base64 yang sah.');
            }

            return $decoded;
        }

        return $key;
    }
}
