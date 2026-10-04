<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rules\BirthDateRule;
use App\Rules\NikRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

final class RulesTest extends TestCase
{
    /** @return list<string> */
    private function errorsFor(object $rule, mixed $value): array
    {
        $errors = [];
        $rule->validate('field', $value, function ($message) use (&$errors): void {
            $errors[] = (string) $message;
        });

        return $errors;
    }

    #[Test]
    #[TestDox('Tanggal lahir di masa depan ditolak')]
    public function tanggal_lahir_masa_depan_ditolak(): void
    {
        $errors = $this->errorsFor(new BirthDateRule, now()->addDay()->toDateString());

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('melebihi hari ini', $errors[0]);
    }

    #[Test]
    #[TestDox('Tanggal lahir hari ini diterima (bayi baru lahir)')]
    public function tanggal_lahir_hari_ini_diterima(): void
    {
        $this->assertSame([], $this->errorsFor(new BirthDateRule, now()->toDateString()));
    }

    #[Test]
    #[TestDox('Usia di atas 120 tahun ditolak sebagai kemungkinan salah ketik')]
    public function usia_tidak_wajar_ditolak(): void
    {
        $errors = $this->errorsFor(new BirthDateRule, now()->subYears(130)->toDateString());

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('120 tahun', $errors[0]);
    }

    #[Test]
    #[TestDox('Usia lanjut yang masih wajar diterima')]
    public function usia_lanjut_wajar_diterima(): void
    {
        $this->assertSame([], $this->errorsFor(
            new BirthDateRule, now()->subYears(95)->toDateString()
        ));
    }

    #[Test]
    #[TestDox('Pesan galat NIK menyebutkan jumlah angka yang dimasukkan')]
    public function pesan_galat_nik_informatif(): void
    {
        $errors = $this->errorsFor(new NikRule, '320123456789012');

        $this->assertNotEmpty($errors);
        // docs/07-ui-ux.md §7: pesan harus menyebut apa yang salah secara spesifik,
        // bukan sekadar "NIK tidak valid".
        $this->assertStringContainsString('15 angka', $errors[0]);
    }

    #[Test]
    #[TestDox('NIK dengan pemisah tetap diterima setelah normalisasi')]
    public function nik_dengan_pemisah_diterima(): void
    {
        $this->assertSame([], $this->errorsFor(new NikRule, '3201-2345-6789-0123'));
    }
}
