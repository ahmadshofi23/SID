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
        $this->assertSame(
            0,
            $result['unverifiable'],
            'Baris yang baru ditulis wajib memakai rumus hash yang berlaku '
            .'sekarang, bukan dihitung sebagai baris yang tidak terverifikasi.'
        );
    }

    /**
     * REGRESI KEP-020, temuan docs/23 §7 butir 2 — uji yang paling penting
     * di berkas ini.
     *
     * Sampai KEP-020, uji seperti ini LULUS padahal seharusnya gagal: rumus
     * hash memakai `$this->changes` di dalam kelas model, yang mengenai
     * properti protected milik HasAttributes dan bukan kolomnya, sehingga
     * kolom `changes` tidak pernah ikut di-hash. Akibatnya nilai lama/baru
     * data penduduk — justru satu-satunya kolom yang berisi datanya — dapat
     * diubah langsung di basis data sementara `sid:verify-audit-chain` tetap
     * melaporkan "rantai utuh".
     *
     * Bila uji ini gagal, audit log kembali kehilangan nilainya sebagai
     * bukti tanpa satu pun tanda di layar.
     */
    #[Test]
    #[TestDox('Perubahan kolom changes di luar aplikasi terdeteksi rantai hash')]
    public function perubahan_kolom_changes_terdeteksi(): void
    {
        $logger = app(AuditLogger::class);

        for ($i = 0; $i < 5; $i++) {
            $logger->log("uji.aksi.{$i}", changes: [
                'status' => ['old' => 'aktif', 'new' => 'pindah'],
            ]);
        }

        $target = AuditLog::query()->orderBy('id')->skip(2)->first();

        // Melewati model: seseorang memutihkan riwayat mutasi lewat SQL
        // langsung, dan membiarkan sisa barisnya apa adanya.
        DB::table('audit_logs')->where('id', $target->id)->update([
            'changes' => json_encode(['status' => ['old' => 'aktif', 'new' => 'aktif']]),
        ]);

        $result = $logger->verifyChain();

        $this->assertFalse(
            $result['valid'],
            'Rantai hash gagal mendeteksi perubahan kolom `changes`. Justru '
            .'kolom itulah yang berisi nilai lama/baru data penduduk; sisanya '
            .'hanya menyebutkan siapa dan kapan.'
        );
        $this->assertSame($target->id, $result['broken_at']);
    }

    /**
     * REGRESI KEP-020, sisi sebaliknya: alarm yang berbunyi atas data JUJUR.
     *
     * MySQL menyimpan kolom JSON dalam bentuk kanoniknya sendiri dan
     * mengembalikan kuncinya terurut, sementara rumus lama meng-encode kedua
     * kolom JSON menurut urutan kunci yang kebetulan dipakai pemanggil.
     * Setiap baris yang kuncinya tidak kebetulan sudah urut karena itu
     * dilaporkan rusak walau tidak pernah disentuh siapa pun — dan
     * `sid:verify-audit-chain` memang berbunyi "RANTAI RUSAK" pada basis data
     * pengembangan sebelum KEP-020.
     *
     * Kunci di bawah dipilih supaya urutan tulisnya PASTI berbeda dari urutan
     * kanonik MySQL (yang mengurutkan menurut panjang lalu leksikal), pada
     * dua tingkat sarang sekaligus.
     *
     * Alarm integritas bukti yang berbunyi atas data jujur akan diabaikan,
     * dan kendali yang diabaikan sama nilainya dengan kendali yang tidak ada.
     * Karena itu ini uji kegagalan rilis, bukan kenyamanan.
     */
    #[Test]
    #[TestDox('Urutan kunci JSON yang diubah MySQL tidak membuat rantai terlihat rusak')]
    public function urutan_kunci_json_tidak_merusak_rantai(): void
    {
        $logger = app(AuditLogger::class);

        $logger->log('uji.kanonik', changes: [
            'nomor_surat' => ['old' => 'A', 'new' => 'B'],
            'jenis_surat' => ['old' => 'X', 'new' => 'Y'],
            'tiket' => ['old' => 1, 'new' => 2],
        ], context: [
            'nomor_surat' => '1/SKTM/3201012001/X/2026',
            'jenis_surat' => 'SKTM',
            'tiket' => 'T261015-C507E9',
        ]);

        $raw = (string) DB::table('audit_logs')->orderByDesc('id')->value('changes');

        $this->assertStringStartsWith(
            '{"tiket"',
            $raw,
            'Prakondisi uji: MySQL harus benar-benar mengurutkan ulang kuncinya. '
            .'Bila tidak, uji ini tidak lagi menguji apa pun — bentuk mentahnya: '
            .$raw
        );

        $result = $logger->verifyChain();

        $this->assertTrue(
            $result['valid'],
            'Baris yang tidak pernah disentuh siapa pun dilaporkan rusak hanya '
            .'karena MySQL mengurutkan ulang kunci JSON-nya. Alarm Sev-1 yang '
            .'berbunyi atas data jujur akan diabaikan.'
        );
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
