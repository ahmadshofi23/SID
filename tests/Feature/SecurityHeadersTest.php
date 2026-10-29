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
    #[TestDox('Skrip sebaris di halaman depan menyandang nonce yang sama dengan header CSP')]
    public function skrip_sebaris_halaman_depan_bernonce(): void
    {
        /*
         * Halaman depan adalah portal publik (KEP-016) dan memuat dua skrip
         * sebaris: pencegah kedipan putih mode gelap, dan data terstruktur
         * JSON-LD. Keduanya hanya berjalan bila nonce-nya cocok dengan
         * direktif script-src pada permintaan ITU.
         *
         * MENGAPA DIUJI DI SINI, BUKAN DIANGGAP JELAS: ketidakcocokan antara
         * keduanya tidak menghasilkan galat apa pun selain di konsol peramban.
         * Akibatnya baru terlihat sebagai mode gelap yang berkedip putih dan
         * cuplikan hasil pencarian yang hilang — gejala yang tak seorang pun
         * menghubungkan dengan CSP.
         */
        $response = $this->get('/')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", $csp, $cocok),
            'Header CSP halaman depan tidak memuat nonce.');

        $response->assertSee('nonce="'.$cocok[1].'"', false);
        $response->assertSee('application/ld+json', false);
    }
}
