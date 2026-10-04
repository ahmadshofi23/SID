<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Autentikasi — FR-AUT-01..06, FR-AUT-10, docs/08 §3.
 */
final class LoginTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'kata-sandi-uji-yang-panjang';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    private function activeOperator(array $overrides = []): User
    {
        $user = $this->userWithRole(Role::OPERATOR);
        $user->forceFill(array_merge(['phone' => '628111222333'], $overrides))->save();

        return $user->fresh(['roles.permissions']);
    }

    #[Test]
    #[TestDox('Login berhasil dengan email dan kata sandi benar')]
    public function login_dengan_email(): void
    {
        $user = $this->activeOperator();

        $this->post('/masuk', [
            'identifier' => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    #[TestDox('Login berhasil dengan nomor ponsel (FR-AUT-01)')]
    public function login_dengan_nomor_ponsel(): void
    {
        $user = $this->activeOperator();

        // Warga mengetik 08xx; sistem menormalkan ke 628xx.
        $this->post('/masuk', [
            'identifier' => '08111222333',
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    #[TestDox('ANTI-ENUMERASI: akun tidak ada & kata sandi salah memberi pesan IDENTIK')]
    public function pesan_galat_identik_untuk_mencegah_enumerasi(): void
    {
        $user = $this->activeOperator();

        $tidakAda = $this->post('/masuk', [
            'identifier' => 'bukan-siapa-siapa@contoh.test',
            'password' => 'kata-sandi-apa-saja-panjang',
        ]);

        $sandiSalah = $this->post('/masuk', [
            'identifier' => $user->email,
            'password' => 'kata-sandi-salah-tapi-panjang',
        ]);

        $pesanA = $this->errorMessage($tidakAda, 'identifier');
        $pesanB = $this->errorMessage($sandiSalah, 'identifier');

        $this->assertNotSame('', $pesanA, 'Harus ada pesan galat.');
        $this->assertSame($pesanA, $pesanB,
            'Pesan galat berbeda akan mengubah halaman login menjadi alat untuk '
            .'memeriksa apakah seseorang terdaftar sebagai warga desa ini (FR-AUT-10).'
        );
        $this->assertGuest();
    }

    #[Test]
    #[TestDox('Akun belum terverifikasi tidak dapat masuk')]
    public function akun_belum_terverifikasi_ditolak(): void
    {
        $user = $this->activeOperator();
        $user->forceFill(['status' => User::STATUS_PENDING])->save();

        $this->post('/masuk', [
            'identifier' => $user->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    #[Test]
    #[TestDox('Akun nonaktif tidak dapat masuk')]
    public function akun_nonaktif_ditolak(): void
    {
        $user = $this->activeOperator();
        $user->forceFill(['status' => User::STATUS_DISABLED])->save();

        $this->post('/masuk', [
            'identifier' => $user->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    #[Test]
    #[TestDox('Akun terkunci setelah 10 percobaan gagal (FR-AUT-03)')]
    public function akun_terkunci_setelah_percobaan_maksimum(): void
    {
        $user = $this->activeOperator();
        $max = (int) config('sid.auth.max_login_attempts');

        for ($i = 0; $i < $max; $i++) {
            // Setiap percobaan dari IP berbeda agar jeda bertingkat per-IP
            // tidak menghalangi pengujian penguncian akun.
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])
                ->post('/masuk', [
                    'identifier' => $user->email,
                    'password' => 'salah-tapi-cukup-panjang',
                ]);
        }

        $user->refresh();

        $this->assertTrue($user->isLocked(), 'Akun seharusnya terkunci setelah '.$max.' percobaan.');

        // Kata sandi BENAR pun harus ditolak selama masih terkunci.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post('/masuk', ['identifier' => $user->email, 'password' => self::PASSWORD]);

        $response->assertSessionHasErrors('identifier');
        $this->assertGuest();

        $this->assertStringContainsString(
            'dikunci',
            $this->errorMessage($response, 'identifier'),
            'Akun terkunci SENGAJA diberi pesan berbeda: tanpa itu pengguna sah '
            .'akan terus mencoba dan memperpanjang penguncian (kebergunaan > '
            .'kebocoran informasi yang sangat kecil).'
        );
    }

    #[Test]
    #[TestDox('Jeda bertingkat aktif setelah 5 percobaan gagal dari IP yang sama')]
    public function jeda_bertingkat_aktif(): void
    {
        $user = $this->activeOperator();

        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/masuk', [
                'identifier' => $user->email,
                'password' => 'salah-tapi-cukup-panjang',
            ]);
        }

        $this->assertStringContainsString(
            'Terlalu banyak percobaan',
            $this->errorMessage($response, 'identifier')
        );
    }

    #[Test]
    #[TestDox('Login berhasil mengatur ulang penghitung percobaan gagal')]
    public function login_berhasil_mereset_penghitung(): void
    {
        $user = $this->activeOperator();

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.1'])->post('/masuk', [
            'identifier' => $user->email, 'password' => 'salah-tapi-panjang',
        ]);

        $this->assertSame(1, $user->fresh()->failed_login_count);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.2'])->post('/masuk', [
            'identifier' => $user->email, 'password' => self::PASSWORD,
        ]);

        $this->assertSame(0, $user->fresh()->failed_login_count);
    }

    #[Test]
    #[TestDox('Login & logout tercatat di audit log (FR-ADM-04)')]
    public function login_tercatat_di_audit_log(): void
    {
        $user = $this->activeOperator();

        $this->post('/masuk', ['identifier' => $user->email, 'password' => self::PASSWORD]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'actor_id' => $user->id,
        ]);

        $this->post('/keluar');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'actor_id' => $user->id,
        ]);
    }

    #[Test]
    #[TestDox('Audit log kegagalan login TIDAK menyimpan identifier yang dicoba')]
    public function audit_log_tidak_menyimpan_identifier_yang_dicoba(): void
    {
        $this->post('/masuk', [
            'identifier' => 'korban@contoh.test',
            'password' => 'kata-sandi-apa-saja-panjang',
        ]);

        $entries = AuditLog::query()->where('action', 'auth.login_failed')->get();

        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString(
                'korban@contoh.test',
                json_encode([$entry->changes, $entry->context, $entry->actor_name]),
                'Audit log tidak boleh menjadi daftar email/ponsel yang pernah dicoba '
                .'penyerang — itu justru membocorkan informasi.'
            );
        }
    }

    #[Test]
    #[TestDox('ID sesi diputar setelah login (anti session fixation)')]
    public function id_sesi_diputar_setelah_login(): void
    {
        $user = $this->activeOperator();

        $this->get('/masuk');
        $before = session()->getId();

        $this->post('/masuk', ['identifier' => $user->email, 'password' => self::PASSWORD]);

        $this->assertNotSame($before, session()->getId());
    }

    #[Test]
    #[TestDox('Halaman terlindungi mengalihkan tamu ke /masuk')]
    public function tamu_dialihkan_ke_halaman_masuk(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/kependudukan/penduduk')->assertRedirect(route('login'));
    }
}
