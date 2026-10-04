<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NameNormalizer;
use App\Support\Pii\Nik;
use App\Support\Pii\PiiCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PiiTest extends TestCase
{
    private const KEY = 'base64:dGVzdGluZy1vbmx5LWtleS0zMi1ieXRlcy1sb25nISE=';

    private const PEPPER = 'pepper-uji';

    private function cipher(): PiiCipher
    {
        return new PiiCipher(self::KEY, self::PEPPER);
    }

    /* --------------------------- Enkripsi -------------------------------- */

    #[Test]
    #[TestDox('NIK dapat dienkripsi dan didekripsi kembali utuh')]
    public function enkripsi_bolak_balik(): void
    {
        $nik = '3201234567890123';

        $this->assertSame($nik, $this->cipher()->decrypt($this->cipher()->encrypt($nik)));
    }

    #[Test]
    #[TestDox('Dua enkripsi nilai sama menghasilkan ciphertext BERBEDA')]
    public function enkripsi_tidak_deterministik(): void
    {
        $cipher = $this->cipher();

        // Penting: bila ciphertext sama untuk nilai sama, penyerang yang
        // mendapat dump basis data dapat mengelompokkan warga dengan NIK
        // yang sama tanpa perlu mendekripsi apa pun.
        $this->assertNotSame(
            $cipher->encrypt('3201234567890123'),
            $cipher->encrypt('3201234567890123'),
        );
    }

    #[Test]
    #[TestDox('Ciphertext yang diubah satu bit pun ditolak (AES-GCM)')]
    public function perubahan_ciphertext_ditolak(): void
    {
        $cipher = $this->cipher();
        $payload = $cipher->encrypt('3201234567890123');

        $tampered = $payload;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($tampered);
    }

    #[Test]
    #[TestDox('Kunci berbeda tidak dapat mendekripsi data')]
    public function kunci_berbeda_gagal(): void
    {
        $payload = $this->cipher()->encrypt('3201234567890123');
        $other = new PiiCipher('base64:'.base64_encode('kunci-lain-32-bytes-panjang-oke!'), self::PEPPER);

        $this->expectException(RuntimeException::class);
        $other->decrypt($payload);
    }

    #[Test]
    #[TestDox('Hash bersifat deterministik dan bergantung pepper')]
    public function hash_deterministik_dan_bergantung_pepper(): void
    {
        $a = $this->cipher();
        $b = new PiiCipher(self::KEY, 'pepper-berbeda');

        $this->assertSame($a->hash('3201234567890123'), $a->hash('3201234567890123'));
        $this->assertNotSame($a->hash('3201234567890123'), $b->hash('3201234567890123'));
    }

    #[Test]
    #[TestDox('Kunci tidak sepanjang 32 byte ditolak sejak awal')]
    public function kunci_pendek_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        new PiiCipher('base64:'.base64_encode('terlalu-pendek'), self::PEPPER);
    }

    /* ----------------------------- NIK ----------------------------------- */

    #[Test]
    #[DataProvider('nikFormats')]
    public function pemeriksaan_format_nik(string $nik, bool $expected): void
    {
        $this->assertSame($expected, Nik::isWellFormed($nik));
    }

    public static function nikFormats(): array
    {
        return [
            'tepat 16 digit' => ['3201234567890123', true],
            '15 digit' => ['320123456789012', false],
            '17 digit' => ['32012345678901234', false],
            'ada huruf' => ['32012345678901aa', false],
            'kosong' => ['', false],
            'ada spasi' => ['3201 2345 6789 0123', false],
        ];
    }

    #[Test]
    #[TestDox('Normalisasi NIK membuang pemisah')]
    public function normalisasi_nik(): void
    {
        $this->assertSame('3201234567890123', Nik::normalize('3201-2345-6789-0123'));
        $this->assertSame('3201234567890123', Nik::normalize(' 3201 2345 6789 0123 '));
    }

    #[Test]
    #[TestDox('Masking menyisakan 4 digit awal & akhir saja')]
    public function masking_nik(): void
    {
        $this->assertSame('3201••••••••0123', Nik::mask('3201234567890123'));
        $this->assertSame(16, mb_strlen(Nik::mask('3201234567890123')));

        // NIK tidak valid tidak boleh membocorkan apa pun.
        $this->assertStringNotContainsString('320', Nik::mask('320'));
    }

    #[Test]
    #[TestDox('NIK dengan isi tidak lazim menghasilkan PERINGATAN, bukan penolakan')]
    public function peringatan_bukan_penolakan(): void
    {
        // Digit 7-8 = 99 -> tanggal tidak mungkin.
        $warnings = Nik::warnings('3201019912900001');

        $this->assertNotEmpty($warnings);
        $this->assertTrue(
            Nik::isWellFormed('3201019912900001'),
            'NIK dengan isi tidak lazim harus tetap LOLOS pemeriksaan format: '
            .'di lapangan banyak NIK sah yang tidak mengikuti pola.'
        );
    }

    #[Test]
    #[TestDox('Jenis kelamin yang tidak sesuai pola NIK menghasilkan peringatan')]
    public function peringatan_jenis_kelamin(): void
    {
        // Digit 7-8 = 55 (> 40) menandakan perempuan.
        $warnings = Nik::warnings('3201015503850001', null, 'L');

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Jenis kelamin', implode(' ', $warnings));
    }

    /* ----------------------- Normalisasi nama ---------------------------- */

    #[Test]
    #[DataProvider('names')]
    public function normalisasi_nama(string $input, string $expected): void
    {
        $this->assertSame($expected, NameNormalizer::normalize($input));
    }

    public static function names(): array
    {
        return [
            'gelar depan' => ['Drs. Bambang Wijaya', 'bambang wijaya'],
            'apostrof' => ["Siti Ma'rufah", 'siti marufah'],
            'satu kata' => ['Suprapto', 'suprapto'],
            'gelar haji' => ['H. Ahmad Hidayat', 'ahmad hidayat'],
            'spasi ganda' => ['Ahmad   Hidayat', 'ahmad hidayat'],
            'spasi tepi' => ['  Ahmad Hidayat  ', 'ahmad hidayat'],
            'huruf besar' => ['AHMAD HIDAYAT', 'ahmad hidayat'],
            'tanda baca' => ['Ahmad, Hidayat.', 'ahmad hidayat'],
        ];
    }
}
