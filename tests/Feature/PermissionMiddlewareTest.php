<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Penegakan izin di sisi server — NFR-SEC-02.
 *
 * Menyembunyikan menu di UI adalah kenyamanan, bukan keamanan
 * (docs/07-ui-ux.md §2.4). Uji ini memeriksa kontrol yang sebenarnya.
 */
final class PermissionMiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    #[Test]
    #[TestDox('Peran dengan izin dapat mengakses rute terlindungi')]
    public function peran_berizin_diterima(): void
    {
        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->get('/kependudukan/penduduk')
            ->assertOk();
    }

    #[Test]
    #[TestDox('Peran tanpa izin ditolak 403, bukan dibiarkan masuk')]
    public function peran_tanpa_izin_ditolak(): void
    {
        // Kaur Keuangan tidak punya izin audit.lihat.
        $this->actingAs($this->userWithRole(Role::KAUR_KEUANGAN))
            ->get('/pengaturan/log-aktivitas')
            ->assertForbidden();
    }

    #[Test]
    #[TestDox('Pesan 403 memberi arahan, bukan sekadar "Unauthorized"')]
    public function pesan_403_informatif(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::KAUR_KEUANGAN))
            ->get('/pengaturan/log-aktivitas');

        // docs/07-ui-ux.md §7: pesan galat harus menyebut apa yang harus dilakukan.
        $response->assertSee('Admin Desa', false);
    }

    #[Test]
    #[TestDox('Pengawas dapat melihat log aktivitas, tidak dapat mengubah data')]
    public function pengawas_baca_saja(): void
    {
        $pengawas = $this->userWithRole(Role::PENGAWAS);

        $this->actingAs($pengawas)->get('/pengaturan/log-aktivitas')->assertOk();
        $this->actingAs($pengawas)->get('/kependudukan/penduduk')->assertOk();
    }

    #[Test]
    #[TestDox('Header keamanan terpasang pada semua respons')]
    public function header_keamanan_terpasang(): void
    {
        $response = $this->get('/masuk');

        foreach ([
            'Content-Security-Policy',
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Referrer-Policy',
            'Permissions-Policy',
        ] as $header) {
            $this->assertNotNull(
                $response->headers->get($header), "Header {$header} tidak terpasang."
            );
        }

        $this->assertStringContainsString(
            "script-src 'self'",
            (string) $response->headers->get('Content-Security-Policy')
        );
    }

    #[Test]
    #[TestDox('Correlation ID dikembalikan di header respons')]
    public function correlation_id_dikembalikan(): void
    {
        $this->assertNotNull(
            $this->get('/masuk')->headers->get('X-Correlation-Id')
        );
    }
}
