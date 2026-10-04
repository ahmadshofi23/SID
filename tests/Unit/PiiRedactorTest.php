<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audit\PiiRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Penyensoran data pribadi sebelum masuk log — docs/08 §5.
 *
 * Aturan mutlak: NIK & nomor KK TIDAK PERNAH muncul di log. Uji ini adalah
 * yang menjaga agar aturan itu tidak bergantung pada ingatan pengembang.
 */
final class PiiRedactorTest extends TestCase
{
    private PiiRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new PiiRedactor;
    }

    #[Test]
    #[TestDox('Field bernama sensitif disensor penuh')]
    public function field_sensitif_disensor(): void
    {
        $result = $this->redactor->redact([
            'nik' => '3201234567890123',
            'nomor_kk' => '3201234567890001',
            'password' => 'rahasia',
            'two_factor_secret' => 'ABCDEF',
            'full_name' => 'Ahmad Hidayat',
        ]);

        $this->assertSame(PiiRedactor::PLACEHOLDER, $result['nik']);
        $this->assertSame(PiiRedactor::PLACEHOLDER, $result['nomor_kk']);
        $this->assertSame(PiiRedactor::PLACEHOLDER, $result['password']);
        $this->assertSame(PiiRedactor::PLACEHOLDER, $result['two_factor_secret']);

        // Nama BUKAN K1 — tetap dicatat agar audit log berguna.
        $this->assertSame('Ahmad Hidayat', $result['full_name']);
    }

    #[Test]
    #[TestDox('Struktur bersarang ikut disensor')]
    public function struktur_bersarang(): void
    {
        $result = $this->redactor->redact([
            'changes' => [
                'nik' => ['old' => '3201234567890123', 'new' => '3201234567890124'],
            ],
        ]);

        $this->assertSame(PiiRedactor::PLACEHOLDER, $result['changes']['nik']);
    }

    #[Test]
    #[TestDox('NIK di dalam teks bebas tetap disensor (jaring pengaman)')]
    public function nik_dalam_teks_bebas(): void
    {
        $result = $this->redactor->redact([
            'catatan' => 'Warga dengan NIK 3201234567890123 mengajukan surat.',
        ]);

        $this->assertStringNotContainsString('3201234567890123', $result['catatan']);
        $this->assertStringContainsString(PiiRedactor::PLACEHOLDER, $result['catatan']);
    }

    #[Test]
    #[TestDox('Nomor ponsel di teks bebas disensor')]
    public function nomor_ponsel_disensor(): void
    {
        foreach (['081234567890', '+6281234567890', '6281234567890'] as $phone) {
            $result = $this->redactor->redactString("Hubungi {$phone} segera.");

            $this->assertStringNotContainsString(
                $phone, $result, "Nomor {$phone} lolos penyensoran."
            );
        }
    }

    #[Test]
    #[TestDox('Angka yang bukan NIK tidak ikut tersensor')]
    public function angka_lain_tidak_tersensor(): void
    {
        $result = $this->redactor->redactString('Terdapat 1500 baris dan 12 galat.');

        $this->assertSame('Terdapat 1500 baris dan 12 galat.', $result);
    }
}
