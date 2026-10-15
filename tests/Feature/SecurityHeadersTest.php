<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Nonce CSP untuk skrip sebaris — NFR-SEC-07, docs/08 §6.
 *
 * Skrip tema di <head> mencegah kedipan putih pada mode gelap, tetapi ia
 * sebaris: tanpa nonce, CSP memblokirnya dan satu-satunya gejala muncul di
 * konsol peramban — tidak ada uji yang gagal, tidak ada galat di log. Karena
 * itu kecocokan antara header dan atribut nonce diuji secara eksplisit.
 */
final class SecurityHeadersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    #[Test]
    #[TestDox('Nonce pada header CSP cocok dengan atribut nonce skrip tema')]
    public function nonce_header_cocok_dengan_skrip(): void
    {
        $response = $this->get('/masuk')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertSame(
            1,
            preg_match("/script-src [^;]*'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp, $matches),
            "Direktif script-src tidak memuat nonce: {$csp}"
        );

        $response->assertSee('nonce="'.$matches[1].'"', false);
    }

    #[Test]
    #[TestDox("script-src tidak pernah memakai 'unsafe-inline'")]
    public function script_src_tanpa_unsafe_inline(): void
    {
        $csp = (string) $this->get('/masuk')->headers->get('Content-Security-Policy');

        $this->assertSame(1, preg_match('/script-src ([^;]*)/', $csp, $matches));
        $this->assertStringNotContainsString("'unsafe-inline'", $matches[1]);
    }

    #[Test]
    #[TestDox('Nonce berganti pada setiap permintaan')]
    public function nonce_berganti_tiap_permintaan(): void
    {
        $nonce = fn (): string => (string) $this->get('/masuk')
            ->headers->get('Content-Security-Policy');

        $this->assertNotSame($nonce(), $nonce());
    }

    #[Test]
    #[TestDox('Halaman depan dirender dengan komponen alert')]
    public function halaman_depan_dirender(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Portal publik dibangun pada Sprint 6')
            ->assertSee('role="status"', false);
    }
}
