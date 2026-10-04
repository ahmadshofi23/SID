<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * TC-ADM-04 — Audit log tidak dapat diubah (docs/09-testing-qa.md §5).
 */
final class AuditLogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    #[Test]
    #[TestDox('Audit log menolak pembaruan di lapis aplikasi')]
    public function menolak_pembaruan(): void
    {
        $entry = app(AuditLogger::class)->log('uji.aksi');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dapat diubah');

        $entry->update(['action' => 'diubah']);
    }

    #[Test]
    #[TestDox('Audit log menolak penghapusan di lapis aplikasi')]
    public function menolak_penghapusan(): void
    {
        $entry = app(AuditLogger::class)->log('uji.aksi');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dapat dihapus');

        $entry->delete();
    }

    #[Test]
    #[TestDox('Rantai hash terbentuk & terverifikasi untuk 10 pencatatan')]
    public function rantai_hash_terverifikasi(): void
    {
        $logger = app(AuditLogger::class);

        for ($i = 0; $i < 10; $i++) {
            $logger->log("uji.aksi.{$i}");
        }

        $result = $logger->verifyChain();

        $this->assertTrue($result['valid'], 'Rantai hash seharusnya utuh.');
        $this->assertSame(10, $result['checked']);
    }

    #[Test]
    #[TestDox('Perubahan baris di luar aplikasi terdeteksi rantai hash')]
    public function perubahan_di_luar_aplikasi_terdeteksi(): void
    {
        $logger = app(AuditLogger::class);

        for ($i = 0; $i < 5; $i++) {
            $logger->log("uji.aksi.{$i}");
        }

        // Melewati model: inilah skenario nyata yang ingin dideteksi —
        // seseorang mengubah log langsung lewat SQL.
        $target = AuditLog::query()->orderBy('id')->skip(2)->first();
        DB::table('audit_logs')->where('id', $target->id)
            ->update(['action' => 'disamarkan']);

        $result = $logger->verifyChain();

        $this->assertFalse($result['valid'],
            'Rantai hash gagal mendeteksi perubahan baris — audit log kehilangan '
            .'nilainya sebagai bukti.'
        );
        $this->assertSame($target->id, $result['broken_at']);
    }

    #[Test]
    #[TestDox('NIK tidak pernah tersimpan di audit log')]
    public function nik_tidak_tersimpan_di_audit_log(): void
    {
        app(AuditLogger::class)->log('uji.aksi', changes: [
            'nik' => ['old' => '3201234567890123', 'new' => '3201234567890124'],
            'catatan' => 'Perubahan untuk NIK 3201234567890123',
        ]);

        $raw = (string) DB::table('audit_logs')->orderByDesc('id')->value('changes');

        $this->assertStringNotContainsString('3201234567890123', $raw);
        $this->assertStringNotContainsString('3201234567890124', $raw);
    }

    #[Test]
    #[TestDox('Ekspor data tercatat beserta jumlah baris & filter')]
    public function ekspor_tercatat_lengkap(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin);
        app(AuditLogger::class)->logExport('residents', 1500, ['region_id' => 'abc']);

        $entry = AuditLog::query()->where('action', AuditLogger::ACTION_EXPORTED)->firstOrFail();

        $this->assertSame(1500, $entry->context['rows']);
        $this->assertSame('residents', $entry->context['entity_type']);
        $this->assertSame($admin->id, $entry->actor_id);
    }

    #[Test]
    #[TestDox('Nama & peran aktor disalin agar log tetap utuh walau akun dihapus')]
    public function identitas_aktor_disalin(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);
        $this->actingAs($admin);

        app(AuditLogger::class)->log('uji.aksi');
        $entry = AuditLog::query()->orderByDesc('id')->firstOrFail();

        $this->assertSame($admin->name, $entry->actor_name);
        $this->assertStringContainsString(Role::ADMIN_DESA, (string) $entry->actor_roles);

        $admin->delete();

        // Nama aktor tetap ada setelah akun dihapus.
        $this->assertSame($admin->name, $entry->fresh()->actor_name);
    }
}
