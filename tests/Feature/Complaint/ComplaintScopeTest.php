<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Auth\AccessScope;
use App\Models\Complaint;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Repositories\ComplaintRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Lingkup akses & penegakan RULE-09 pada pengaduan — Sprint 7 Bagian 1.
 *
 * Yang dijaga kelas ini bukan "repository-nya jalan", melainkan empat janji
 * yang masing-masing pernah dilanggar pada proyek sejenis:
 *   1. Warga melihat pengaduannya SENDIRI, bukan pengaduan satu KK — berbeda
 *      dari surat (RULE-11), karena yang diadukan bisa anggota keluarganya.
 *   2. Kepala Dusun tidak melihat pengaduan dusun lain, dan juga tidak
 *      melihat pengaduan tanpa wilayah.
 *   3. Pengaduan anonim tidak dapat menyimpan identitas pelapor — ditolak
 *      BASIS DATA, bukan hanya kode aplikasi (RULE-09).
 *   4. Penolakan tanpa alasan tertulis ditolak basis data (FR-PGD-03).
 */
final class ComplaintScopeTest extends TestCase
{
    use DatabaseTransactions;

    private Region $dusunKrajan;

    private Region $dusunSidomulyo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $this->dusunSidomulyo = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
    }

    /* ============================ Lingkup warga =========================== */

    #[Test]
    #[TestDox('Warga hanya melihat pengaduan yang ia laporkan sendiri, bukan pengaduan anggota KK lain')]
    public function warga_hanya_melihat_pengaduan_sendiri(): void
    {
        $warga = $this->userWithRole(Role::WARGA);
        $wargaLain = $this->userWithRole(Role::WARGA);

        $milikSendiri = $this->makeComplaint(['reporter_user_id' => $warga->id]);
        $milikOrangLain = $this->makeComplaint(['reporter_user_id' => $wargaLain->id]);

        $repo = $this->repoFor($warga);

        $this->assertNotNull($repo->find($milikSendiri->id));
        $this->assertNull(
            $repo->find($milikOrangLain->id),
            'KEBOCORAN: warga dapat membuka pengaduan warga lain.'
        );
    }

    #[Test]
    #[TestDox('Pengaduan anonim tidak terlihat oleh pelapornya sendiri — jalurnya kode pelacakan')]
    public function pengaduan_anonim_tidak_terlihat_di_daftar_warga(): void
    {
        $warga = $this->userWithRole(Role::WARGA);

        /*
         * Konsekuensi langsung RULE-09, dan memang dikehendaki: tidak ada
         * kolom apa pun yang menautkan pengaduan ini ke akun yang membuatnya,
         * sehingga tidak ada kueri yang dapat menemukannya kembali untuknya.
         * Yang menggantikannya adalah kode pelacakan.
         */
        $anonim = $this->makeComplaint([
            'is_anonymous' => true,
            'reporter_user_id' => null,
        ]);

        $this->assertNull($this->repoFor($warga)->find($anonim->id));
        $this->assertNotNull($this->repoFor($warga)->findByTrackingCode($anonim->tracking_code));
    }

    /* =========================== Lingkup wilayah ========================== */

    #[Test]
    #[TestDox('Kepala Dusun tidak melihat pengaduan dusun lain')]
    public function kepala_dusun_tidak_melihat_dusun_lain(): void
    {
        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $diKrajan = $this->makeComplaint(['region_id' => $this->dusunKrajan->id]);
        $diSidomulyo = $this->makeComplaint(['region_id' => $this->dusunSidomulyo->id]);

        $repo = $this->repoFor($kadus);

        $this->assertNotNull($repo->find($diKrajan->id));
        $this->assertNull(
            $repo->find($diSidomulyo->id),
            'KEBOCORAN: Kepala Dusun dapat membuka pengaduan dusun lain.'
        );
    }

    #[Test]
    #[TestDox('Kepala Dusun juga tidak melihat pengaduan tanpa wilayah — gagal tertutup')]
    public function kepala_dusun_tidak_melihat_pengaduan_tanpa_wilayah(): void
    {
        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);
        $tanpaWilayah = $this->makeComplaint(['region_id' => null]);

        $this->assertNull($this->repoFor($kadus)->find($tanpaWilayah->id));

        // Admin Desa, yang lingkupnya seluruh desa, tetap melihatnya —
        // kalau tidak, pengaduan tanpa lokasi tidak akan pernah ditriase.
        $admin = $this->userWithRole(Role::ADMIN_DESA);
        $this->assertNotNull($this->repoFor($admin)->find($tanpaWilayah->id));
    }

    #[Test]
    #[TestDox('Anonim (tanpa akun) tidak melihat satu baris pun lewat lingkup biasa')]
    public function tamu_tidak_melihat_apa_pun(): void
    {
        $pengaduan = $this->makeComplaint([]);

        $repo = new ComplaintRepository(AccessScope::guest());

        $this->assertNull($repo->find($pengaduan->id));
        $this->assertSame(0, $repo->countOpen());
    }

    /* ======================= RULE-09 di basis data ======================== */

    #[Test]
    #[TestDox('RULE-09: basis data menolak pengaduan anonim yang menyimpan identitas pelapor')]
    public function basis_data_menolak_anonim_beridentitas(): void
    {
        $warga = $this->userWithRole(Role::WARGA);

        /*
         * Sengaja menulis LANGSUNG ke model, melewati seluruh lapis layanan
         * dan validasi. Itulah yang diuji: bila suatu hari kode aplikasi
         * keliru — atau seseorang memperbaiki data lewat SQL — CHECK constraint
         * tetap menolak. Janji anonim kepada warga tidak boleh bergantung pada
         * satu baris kode yang benar.
         */
        foreach ([
            'reporter_user_id' => $warga->id,
            'reporter_name' => 'Siti Aminah',
            'reporter_phone' => '081234567890',
        ] as $kolom => $nilai) {
            try {
                $this->makeComplaint(['is_anonymous' => true, $kolom => $nilai]);
                $this->fail("CHECK chk_complaints_anon tidak menolak `{$kolom}` pada pengaduan anonim.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('chk_complaints_anon', $e->getMessage());
            }
        }
    }

    #[Test]
    #[TestDox('FR-PGD-03: basis data menolak penolakan tanpa alasan tertulis')]
    public function basis_data_menolak_penolakan_tanpa_alasan(): void
    {
        try {
            $this->makeComplaint([
                'status' => Complaint::STATUS_REJECTED,
                'resolution' => null,
            ]);
            $this->fail('CHECK chk_complaints_tolak_beralasan tidak menolak penolakan tanpa alasan.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_complaints_tolak_beralasan', $e->getMessage());
        }
    }

    /* ========================== Tenggat & SLA ============================= */

    #[Test]
    #[TestDox('KEP-018: tenggat yang berlaku berpindah mengikuti status, kedua kolom tetap utuh')]
    public function tenggat_aktif_mengikuti_status(): void
    {
        $pengaduan = $this->makeComplaint([
            'status' => Complaint::STATUS_NEW,
            'triage_due_at' => now()->addDays(2),
            'due_at' => null,
        ]);

        $this->assertTrue($pengaduan->activeDueAt()?->equalTo($pengaduan->triage_due_at));
        $this->assertFalse($pengaduan->isOverdue());

        $pengaduan->forceFill([
            'status' => Complaint::STATUS_ACCEPTED,
            'due_at' => now()->subDay(),
        ])->save();

        // Tenggat triase TIDAK ditimpa — itu seluruh alasan KEP-018 ada.
        $this->assertNotNull($pengaduan->triage_due_at);
        $this->assertTrue($pengaduan->activeDueAt()?->equalTo($pengaduan->due_at));
        $this->assertTrue($pengaduan->isOverdue());
    }

    #[Test]
    #[TestDox('Hitungan terlambat memakai satu definisi yang sama untuk triase dan penyelesaian')]
    public function hitungan_terlambat_mencakup_dua_tenggat(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->makeComplaint([
            'status' => Complaint::STATUS_NEW,
            'triage_due_at' => now()->subDay(),
        ]);
        $this->makeComplaint([
            'status' => Complaint::STATUS_IN_PROGRESS,
            'triage_due_at' => now()->subWeek(),
            'due_at' => now()->subHour(),
        ]);
        // Tepat waktu: tidak boleh ikut terhitung.
        $this->makeComplaint([
            'status' => Complaint::STATUS_ACCEPTED,
            'triage_due_at' => now()->subWeek(),
            'due_at' => now()->addWeek(),
        ]);
        // Sudah selesai meski dulu terlambat: bukan pekerjaan tertunggak.
        $this->makeComplaint([
            'status' => Complaint::STATUS_DONE,
            'due_at' => now()->subMonth(),
            'resolved_at' => now()->subDay(),
        ]);

        $this->assertSame(2, $this->repoFor($admin)->countOverdue());
        $this->assertSame(3, $this->repoFor($admin)->countOpen());
    }

    /* ======================== Ringkasan publik ============================ */

    #[Test]
    #[TestDox('FR-PGD-07: hanya pengaduan selesai yang ditandai terbit yang keluar ke portal')]
    public function hanya_yang_terbit_dan_selesai_keluar_ke_portal(): void
    {
        $terbit = $this->makeComplaint([
            'status' => Complaint::STATUS_DONE,
            'resolution' => 'Jalan sudah diperbaiki.',
            'resolved_at' => now(),
            'is_published' => true,
        ]);
        $selesaiTanpaIzin = $this->makeComplaint([
            'status' => Complaint::STATUS_DONE,
            'resolution' => 'Sudah ditangani.',
            'resolved_at' => now(),
            'is_published' => false,
        ]);
        $terbitTapiBelumSelesai = $this->makeComplaint([
            'status' => Complaint::STATUS_IN_PROGRESS,
            'is_published' => true,
        ]);

        $ids = (new ComplaintRepository(AccessScope::guest()))->published()
            ->pluck('id')->all();

        $this->assertContains($terbit->id, $ids);
        $this->assertNotContains($selesaiTanpaIzin->id, $ids);
        $this->assertNotContains($terbitTapiBelumSelesai->id, $ids);
    }

    /* ============================== Pembantu ============================== */

    private function repoFor(User $user): ComplaintRepository
    {
        return new ComplaintRepository(AccessScope::forUser($user));
    }

    /** @param array<string, mixed> $attributes */
    private function makeComplaint(array $attributes): Complaint
    {
        $suffix = strtoupper(Str::random(8));

        $complaint = new Complaint;
        $complaint->forceFill([
            'ticket_number' => 'P'.now()->format('ymd').'-'.$suffix,
            'tracking_code' => $suffix.strtoupper(Str::random(8)),
            'category_code' => 'infrastruktur',
            'title' => 'Jalan rusak di depan balai desa',
            'body' => 'Lubang selebar satu meter sudah dua bulan dibiarkan.',
            'status' => Complaint::STATUS_NEW,
            'priority' => Complaint::PRIORITY_NORMAL,
            ...$attributes,
        ])->save();

        return $complaint->refresh();
    }
}
