<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Alat bantu pencadangan kunci — KEP-006.
 */
final class PiiKeyCommandTest extends TestCase
{
    #[Test]
    #[TestDox('Perintah TIDAK PERNAH menampilkan kunci itu sendiri')]
    public function tidak_pernah_menampilkan_kunci(): void
    {
        /*
         * Jaminan terpenting dari perintah ini. Kunci yang pernah tampil di
         * layar akan tertinggal di riwayat terminal, rekaman rapat daring,
         * tangkapan layar dukungan teknis, dan log sesi SSH.
         */
        $this->artisan('sid:pii-key')
            ->doesntExpectOutputToContain((string) config('sid.pii.key'))
            ->doesntExpectOutputToContain((string) config('sid.pii.pepper'))
            ->assertSuccessful();
    }

    #[Test]
    #[TestDox('Sidik jari kunci ditampilkan dalam bentuk yang mudah disalin')]
    public function sidik_jari_ditampilkan(): void
    {
        $expected = $this->fingerprintOf((string) config('sid.pii.key'));

        $this->artisan('sid:pii-key')
            ->expectsOutputToContain($expected)
            ->assertSuccessful();
    }

    #[Test]
    #[TestDox('Sidik jari kunci dan pepper berbeda')]
    public function sidik_jari_kunci_dan_pepper_berbeda(): void
    {
        $this->assertNotSame(
            $this->fingerprintOf((string) config('sid.pii.key')),
            $this->fingerprintOf((string) config('sid.pii.pepper')),
        );
    }

    #[Test]
    #[TestDox('Sidik jari tidak membocorkan kunci (hanya 16 dari 64 karakter hash)')]
    public function sidik_jari_tidak_membocorkan_kunci(): void
    {
        $key = (string) config('sid.pii.key');
        $fingerprint = str_replace('-', '', $this->fingerprintOf($key));

        $this->assertSame(16, strlen($fingerprint));
        $this->assertStringStartsWith(
            strtolower($fingerprint), hash('sha256', $key),
            'Sidik jari harus merupakan potongan awal SHA-256, bukan kunci itu sendiri.'
        );
    }

    #[Test]
    #[TestDox('Perintah gagal dengan arahan jelas bila kunci belum diisi')]
    public function gagal_bila_kunci_kosong(): void
    {
        config(['sid.pii.key' => '', 'sid.pii.pepper' => '']);

        $this->artisan('sid:pii-key')
            ->expectsOutputToContain('sid:generate-pii-keys')
            ->assertFailed();
    }

    private function fingerprintOf(string $value): string
    {
        return implode('-', str_split(strtoupper(substr(hash('sha256', $value), 0, 16)), 4));
    }
}
