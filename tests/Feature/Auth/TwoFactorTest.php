<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * 2FA — FR-AUT-05, docs/08 §3.
 */
final class TwoFactorTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'kata-sandi-uji-yang-panjang';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    #[Test]
    #[TestDox('Admin Desa tanpa 2FA dipaksa ke halaman pemasangan')]
    public function admin_tanpa_2fa_dipaksa_memasang(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA, withTwoFactor: false);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertRedirect(route('two-factor.setup'));
    }

    #[Test]
    #[TestDox('Operator tidak diwajibkan 2FA')]
    public function operator_tidak_diwajibkan_2fa(): void
    {
        $operator = $this->userWithRole(Role::OPERATOR);

        $this->assertFalse($operator->requiresTwoFactor());
        $this->actingAs($operator)->get('/dashboard')->assertOk();
    }

    #[Test]
    #[TestDox('Halaman pemasangan 2FA tetap dapat diakses walau 2FA diwajibkan')]
    public function halaman_pemasangan_tetap_dapat_diakses(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA, withTwoFactor: false);

        $this->actingAs($admin)->get('/dua-faktor/pasang')->assertOk();
    }

    #[Test]
    #[TestDox('2FA aktif setelah kode benar; kode pemulihan diberikan sekali')]
    public function pemasangan_2fa_berhasil(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA, withTwoFactor: false);
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $response = $this->actingAs($admin)
            ->withSession(['2fa.pending_secret' => $secret])
            ->post('/dua-faktor/pasang', ['code' => $code]);

        $response->assertOk();

        $admin->refresh();
        $this->assertTrue($admin->hasTwoFactorEnabled());
        $this->assertCount(8, $admin->recoveryCodes(), 'Harus ada 8 kode pemulihan.');
    }

    #[Test]
    #[TestDox('Kode TOTP salah menolak pemasangan')]
    public function kode_salah_menolak_pemasangan(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA, withTwoFactor: false);
        $secret = app(TwoFactorService::class)->generateSecret();

        $this->actingAs($admin)
            ->withSession(['2fa.pending_secret' => $secret])
            ->post('/dua-faktor/pasang', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    #[TestDox('Login dengan 2FA aktif TIDAK langsung mengautentikasi sesi')]
    public function login_dengan_2fa_belum_mengautentikasi(): void
    {
        $admin = $this->enabledTwoFactorAdmin($secret);

        $response = $this->post('/masuk', [
            'identifier' => $admin->email,
            'password' => self::PASSWORD,
        ]);

        // Sesi harus MASIH tamu sampai kode 2FA diverifikasi — bila sudah
        // terotentikasi di tahap ini, 2FA hanya menjadi hiasan.
        $this->assertGuest();
        $response->assertRedirectContains('/masuk/dua-faktor/');
    }

    #[Test]
    #[TestDox('Kode TOTP benar menyelesaikan login')]
    public function kode_benar_menyelesaikan_login(): void
    {
        $admin = $this->enabledTwoFactorAdmin($secret);

        $redirect = $this->post('/masuk', [
            'identifier' => $admin->email,
            'password' => self::PASSWORD,
        ])->headers->get('Location');

        $challenge = basename((string) $redirect);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->post("/masuk/dua-faktor/{$challenge}", ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    #[Test]
    #[TestDox('Kode pemulihan dapat dipakai dan langsung hangus (sekali pakai)')]
    public function kode_pemulihan_sekali_pakai(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA, withTwoFactor: false);
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();
        $codes = $service->confirm($admin, $secret);
        $admin->refresh();

        $this->assertTrue($service->consumeRecoveryCode($admin, $codes[0]));
        $this->assertCount(7, $admin->fresh()->recoveryCodes());

        // Kode yang sama tidak boleh dapat dipakai dua kali.
        $this->assertFalse(
            $service->consumeRecoveryCode($admin->fresh(), $codes[0]),
            'Kode pemulihan yang sudah dipakai harus hangus.'
        );
    }

    #[Test]
    #[TestDox('Rahasia TOTP disimpan terenkripsi, bukan teks terang')]
    public function rahasia_totp_terenkripsi(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA, withTwoFactor: false);
        $secret = app(TwoFactorService::class)->generateSecret();
        app(TwoFactorService::class)->confirm($admin, $secret);

        $raw = DB::table('users')
            ->where('id', $admin->id)->value('two_factor_secret');

        $stored = is_resource($raw) ? (string) stream_get_contents($raw) : (string) $raw;

        $this->assertStringNotContainsString($secret, $stored,
            'Rahasia TOTP tersimpan sebagai teks terang — siapa pun yang membaca '
            .'dump basis data dapat membuat kode 2FA.'
        );
        $this->assertSame($secret, $admin->fresh()->twoFactorSecret());
    }

    private function enabledTwoFactorAdmin(?string &$secret): User
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA, withTwoFactor: false);
        $secret = app(TwoFactorService::class)->generateSecret();
        app(TwoFactorService::class)->confirm($admin, $secret);

        return $admin->fresh(['roles.permissions']);
    }
}
