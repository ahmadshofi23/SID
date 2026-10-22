<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Auth\AccessScope;
use App\Models\Complaint;
use App\Models\ComplaintAction;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Repositories\ComplaintRepository;
use App\Services\Complaint\ComplaintService;
use App\Support\VillageTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Mesin status, SLA, penugasan & eskalasi pengaduan — Sprint 7 Bagian 2.
 *
 * Yang dijaga kelas ini:
 *   1. Mesin status FR-PGD-03 benar-benar menutup transisi yang tidak sah,
 *      dan penolakan tanpa alasan tertolak sebelum menyentuh basis data.
 *   2. Dua tenggat SLA dihitung pada KALENDER DESA dan hari kerja (KEP-010),
 *      serta tenggat triase tidak pernah ditimpa (KEP-018).
 *   3. RULE-09 tidak dapat dilanggar lewat jalur layanan, bahkan ketika
 *      formulir mengirim identitas pelapor pada pengaduan anonim.
 *   4. Notifikasi masuk ke orang yang benar — termasuk TIDAK masuk ke
 *      pelapor anonim, yang memang tidak boleh punya penerima.
 *   5. Eskalasi idempoten: dijalankan dua kali tidak mengirim dua peringatan.
 */
final class ComplaintWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private User $kasi;

    private User $kadusKrajan;

    private User $warga;

    private Region $dusunKrajan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();

        $this->kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);
        $this->kadusKrajan = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);
        $this->warga = $this->userWithRole(Role::WARGA);
    }

    /* ============================== Pengajuan ============================= */

    #[Test]
    #[TestDox('Pengaduan baru menerima tiket, kode pelacakan, dan tenggat triase 2 hari kerja')]
    public function pengaduan_baru_lengkap(): void
    {
        $this->travelTo(VillageTime::local(now())->setDate(2026, 10, 19)->setTime(9, 0)->utc());

        $pengaduan = $this->service()->create(
            AccessScope::forUser($this->warga),
            $this->input(),
            ip: '203.0.113.7',
        );

        $this->assertSame(Complaint::STATUS_NEW, $pengaduan->status);
        $this->assertMatchesRegularExpression('/^P\d{6}-[A-Z0-9]{5}$/', $pengaduan->ticket_number);
        $this->assertSame(16, mb_strlen($pengaduan->tracking_code));
        $this->assertSame($this->warga->id, $pengaduan->reporter_user_id);

        // Senin 19 Okt + 2 hari kerja = Rabu 21 Okt, akhir hari pada kalender desa.
        $this->assertSame(
            '2026-10-21',
            VillageTime::local($pengaduan->triage_due_at)->toDateString(),
        );

        // Tenggat penyelesaian BELUM ada: ia dihitung dari saat triase.
        $this->assertNull($pengaduan->due_at);

        // Riwayat terbentuk sejak baris pertama, bukan baru saat ada petugas.
        $this->assertSame(1, $pengaduan->actions()->count());
    }

    #[Test]
    #[TestDox('RULE-09: identitas yang dikirim formulir dibuang pada pengaduan anonim')]
    public function identitas_dibuang_pada_pengaduan_anonim(): void
    {
        /*
         * Formulir sengaja mengirim ketiga kolom identitas sekaligus dengan
         * pengaduan ditandai anonim. Inilah bentuk kekeliruan yang paling
         * mungkin terjadi di dunia nyata: isian nama dan telepon tetap
         * terkirim karena warga sudah mengisinya SEBELUM mencentang "anonim".
         */
        $pengaduan = $this->service()->create(
            AccessScope::forUser($this->warga),
            [
                ...$this->input(),
                'is_anonymous' => true,
                'reporter_name' => 'Siti Aminah',
                'reporter_phone' => '081234567890',
            ],
        );

        $this->assertTrue($pengaduan->is_anonymous);
        $this->assertNull($pengaduan->reporter_user_id);
        $this->assertNull($pengaduan->reporter_name);
        $this->assertNull($pengaduan->reporter_phone);
        $this->assertSame('Anonim', $pengaduan->reporterLabel());
    }

    #[Test]
    #[TestDox('FR-PGD-08: pengaduan ke-11 dari satu IP dalam 24 jam ditolak dengan pesan yang dapat dibaca')]
    public function pembatasan_laju_per_ip(): void
    {
        $scope = AccessScope::forUser($this->warga);

        for ($i = 0; $i < 10; $i++) {
            $this->service()->create($scope, $this->input(), ip: '203.0.113.9');
        }

        // IP lain tidak terpengaruh: yang dibatasi jaringan, bukan desa.
        $this->service()->create($scope, $this->input(), ip: '203.0.113.10');

        $this->expectException(ValidationException::class);
        $this->service()->create($scope, $this->input(), ip: '203.0.113.9');
    }

    /* ============================ Mesin status ============================ */

    #[Test]
    #[TestDox('FR-PGD-03: transisi yang tidak sah ditolak, tanpa menyentuh basis data')]
    public function transisi_tidak_sah_ditolak(): void
    {
        $pengaduan = $this->makeComplaint();
        $scope = AccessScope::forUser($this->kasi);

        // `baru` tidak dapat langsung `diproses`: ia harus ditriase dulu.
        try {
            $this->service()->startWork($scope, $pengaduan);
            $this->fail('Transisi baru → diproses seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak dapat diubah', $e->getMessage());
        }

        $this->assertSame(Complaint::STATUS_NEW, $pengaduan->fresh()?->status);

        // Status akhir tidak dapat dihidupkan kembali.
        $selesai = $this->service()->resolve(
            $scope,
            $this->service()->accept($scope, $pengaduan),
            'Sudah ditangani.',
        );

        $this->expectException(ValidationException::class);
        $this->service()->startWork($scope, $selesai);
    }

    #[Test]
    #[TestDox('FR-PGD-03: penolakan tanpa alasan ditolak di lapis layanan, sebelum CHECK basis data')]
    public function penolakan_tanpa_alasan_ditolak(): void
    {
        $pengaduan = $this->makeComplaint();

        try {
            $this->service()->reject(AccessScope::forUser($this->kasi), $pengaduan, '   ');
            $this->fail('Penolakan tanpa alasan seharusnya ditolak.');
        } catch (ValidationException $e) {
            /*
             * Yang diuji di sini BUKAN bahwa aturannya berlaku — itu sudah
             * dijaga CHECK constraint. Yang diuji: warga menerima pesan
             * validasi yang dapat dibaca, bukan galat SQL.
             */
            $this->assertStringContainsString('alasan tertulis', $e->getMessage());
        }

        $this->assertSame(Complaint::STATUS_NEW, $pengaduan->fresh()?->status);
    }

    #[Test]
    #[TestDox('Peran tanpa izin tindak lanjut ditolak 403 di lapis layanan, bukan hanya di rute')]
    public function tanpa_izin_ditolak(): void
    {
        $pengaduan = $this->makeComplaint();

        $this->expectException(HttpException::class);
        $this->service()->accept(AccessScope::forUser($this->warga), $pengaduan);
    }

    /* ========================= Tenggat & KEP-018 ========================== */

    #[Test]
    #[TestDox('KEP-018: triase mengisi tenggat penyelesaian tanpa menimpa tenggat triase')]
    public function triase_tidak_menimpa_tenggat_triase(): void
    {
        $this->travelTo(VillageTime::local(now())->setDate(2026, 10, 19)->setTime(9, 0)->utc());

        $pengaduan = $this->service()->create(AccessScope::forUser($this->warga), $this->input());
        $tenggatTriase = $pengaduan->triage_due_at;

        // Ditriase SETELAH batas triase terlampaui — keadaan yang justru
        // paling penting dicatat.
        $this->travelTo(VillageTime::local(now())->setDate(2026, 10, 26)->setTime(9, 0)->utc());

        $pengaduan = $this->service()->accept(AccessScope::forUser($this->kasi), $pengaduan);

        $this->assertTrue($pengaduan->triage_due_at?->equalTo($tenggatTriase));
        $this->assertNotNull($pengaduan->due_at);

        /*
         * Tenggat penyelesaian dihitung dari saat TRIASE (26 Okt), bukan dari
         * saat pengaduan masuk (19 Okt). 14 hari kerja dari Senin 26 Okt
         * jatuh pada Jumat 13 Nov.
         */
        $this->assertSame('2026-11-13', VillageTime::local($pengaduan->due_at)->toDateString());

        // Keterlambatan triase tetap dapat dibaca setelah pengaduan ditangani —
        // itu seluruh alasan KEP-018 ada.
        $pengaduan->load('actions');
        $this->assertTrue($pengaduan->missedTriage());
    }

    #[Test]
    #[TestDox('SLA dibaca dari settings sehingga desa dapat menyetelnya tanpa rilis baru')]
    public function sla_dapat_disetel_desa(): void
    {
        Setting::put('complaint.triage_sla_days', 1);

        $this->travelTo(VillageTime::local(now())->setDate(2026, 10, 19)->setTime(9, 0)->utc());

        $pengaduan = $this->service()->create(AccessScope::forUser($this->warga), $this->input());

        $this->assertSame(
            '2026-10-20',
            VillageTime::local($pengaduan->triage_due_at)->toDateString(),
        );
    }

    /* ============================= Penugasan ============================== */

    #[Test]
    #[TestDox('FR-PGD-04: penerima tugas diberi tahu, dan catatan penugasan tidak dibaca pelapor')]
    public function penugasan_memberi_tahu_penerima(): void
    {
        $pengaduan = $this->makeComplaint(['region_id' => $this->dusunKrajan->id]);
        $kasiLain = $this->userWithRole(Role::KASI_PELAYANAN);

        $this->service()->assign(
            AccessScope::forUser($this->kasi),
            $pengaduan,
            $kasiLain,
            'Mohon dicek ke lokasi.',
        );

        $this->assertSame($kasiLain->id, $pengaduan->fresh()?->assigned_to);
        $this->assertSame(1, $this->notificationsOf($kasiLain, 'pengaduan.tugas'));

        /*
         * Koordinasi internal tidak otomatis menjadi bacaan pelapor. Catatan
         * penugasan menyebut nama petugas dan sering memuat dugaan yang belum
         * terbukti; pelapor membaca TINDAK LANJUT, bukan percakapan kantor.
         */
        $catatan = ComplaintAction::query()
            ->where('complaint_id', $pengaduan->id)
            ->where('note', 'like', 'Ditugaskan%')
            ->firstOrFail();

        $this->assertFalse($catatan->is_visible_to_reporter);
    }

    #[Test]
    #[TestDox('Kepala Dusun tidak dapat menjadi penerima tugas — matriks RBAC memberinya baca saja')]
    public function penugasan_ke_pengguna_tak_berwenang_ditolak(): void
    {
        $pengaduan = $this->makeComplaint();

        /*
         * Kepala Dusun sengaja dipakai di sini, bukan contoh yang dibuat-buat:
         * matriks RBAC docs/03 §4.9 memberinya "R(wilayah)" atas pengaduan —
         * ia MEMBACA pengaduan di dusunnya dan tidak menindaklanjutinya.
         * Menugaskan kepadanya akan membuat pengaduan resmi "ada yang
         * menangani" sementara tidak seorang pun dapat menyentuhnya.
         */
        $this->assertFalse($this->kadusKrajan->hasPermission('pengaduan.tindaklanjut'));

        $this->expectException(ValidationException::class);
        $this->service()->assign(
            AccessScope::forUser($this->kasi),
            $pengaduan,
            $this->kadusKrajan,
        );
    }

    /* ============================ Notifikasi ============================== */

    #[Test]
    #[TestDox('Pelapor diberi tahu setiap perubahan status; pelapor anonim tidak punya penerima')]
    public function notifikasi_status_ke_pelapor(): void
    {
        $scope = AccessScope::forUser($this->kasi);

        $milikWarga = $this->makeComplaint(['reporter_user_id' => $this->warga->id]);
        $this->service()->accept($scope, $milikWarga);

        $this->assertSame(1, $this->notificationsOf($this->warga, 'pengaduan.status'));

        /*
         * Pengaduan anonim: tidak ada akun penerima, dan itu BUKAN kegagalan
         * yang perlu ditambal. Mencari-cari penerimanya berarti melanggar
         * janji yang dibuat kepada pelapor (RULE-09).
         */
        $anonim = $this->makeComplaint(['is_anonymous' => true, 'reporter_user_id' => null]);
        $this->service()->accept($scope, $anonim);

        $this->assertSame(
            0,
            DatabaseNotification::query()->whereJsonContains('data->kategori', 'pengaduan.status')->count(),
            'Pengaduan anonim tidak boleh menghasilkan notifikasi status kepada siapa pun.'
        );
    }

    #[Test]
    #[TestDox('Hanya peran yang dapat MENINDAKLANJUTI diberi tahu pengaduan baru, bukan semua pemilik pengaduan.lihat')]
    public function notifikasi_pengaduan_baru_hanya_ke_yang_dapat_bertindak(): void
    {
        $kades = $this->userWithRole(Role::KEPALA_DESA);

        $this->service()->create(AccessScope::forUser($this->warga), $this->input());

        $this->assertSame(1, $this->notificationsOf($this->kasi, 'pengaduan.baru'));

        /*
         * Kepala Desa dan Kepala Dusun memegang `pengaduan.lihat` tetapi tidak
         * `pengaduan.tindaklanjut` (matriks RBAC docs/03 §4.9: KD "R",
         * KS "R(wilayah)"). Notifikasi tentang pekerjaan yang tidak dapat
         * mereka kerjakan akan membuat lonceng mereka selalu merah — dan
         * lonceng yang selalu merah berhenti dibaca, termasuk ketika yang
         * masuk benar-benar mendesak.
         */
        $this->assertSame(0, $this->notificationsOf($kades, 'pengaduan.baru'));
        $this->assertSame(0, $this->notificationsOf($this->kadusKrajan, 'pengaduan.baru'));
    }

    #[Test]
    #[TestDox('Petugas berlingkup wilayah hanya diberi tahu pengaduan di wilayahnya, termasuk yang disebut per RT')]
    public function notifikasi_pengaduan_baru_mengikuti_wilayah(): void
    {
        /*
         * Hari ini tidak ada peran bawaan yang SEKALIGUS dibatasi wilayah dan
         * boleh menindaklanjuti, jadi izinnya dipasang di sini pada peran
         * Kepala Dusun. Yang diuji adalah NOTIFIER-nya, bukan seedernya:
         * penyaringan wilayah ini adalah sifat keamanan, bukan kenyamanan —
         * notifikasi tentang pengaduan yang kemudian menghasilkan 404 ketika
         * ditekan justru memberi tahu bahwa ada pengaduan di tempat lain,
         * yaitu kebocoran yang dicegah aturan 404 (docs/06 §4).
         */
        $tindakLanjut = Permission::query()->where('code', 'pengaduan.tindaklanjut')->firstOrFail();
        Role::query()->where('code', Role::KEPALA_DUSUN)->firstOrFail()
            ->permissions()->syncWithoutDetaching([$tindakLanjut->id]);

        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
        $kadusKrajan = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);
        $kadusLain = $this->userWithRole(Role::KEPALA_DUSUN, $dusunLain);

        /*
         * Pengaduan disebutkan di RT, sementara Kepala Dusun ditugaskan pada
         * DUSUN. Mencocokkan region_id secara langsung membuat ia tidak pernah
         * diberi tahu tentang pengaduan di RT-nya sendiri.
         */
        $this->service()->create(AccessScope::forUser($this->warga), [
            ...$this->input(),
            'region_id' => $this->firstRtOf($this->dusunKrajan)->id,
        ]);

        $this->assertSame(1, $this->notificationsOf($kadusKrajan, 'pengaduan.baru'));
        $this->assertSame(0, $this->notificationsOf($kadusLain, 'pengaduan.baru'));

        // Kasi, yang lingkupnya seluruh desa, selalu diberi tahu.
        $this->assertSame(1, $this->notificationsOf($this->kasi, 'pengaduan.baru'));
    }

    #[Test]
    #[TestDox('Warga tidak menerima notifikasi antrean petugas walau punya izin pengaduan.lihat')]
    public function warga_tidak_menerima_notifikasi_antrean(): void
    {
        $this->service()->create(AccessScope::forUser($this->warga), $this->input());

        $this->assertSame(0, $this->notificationsOf($this->warga, 'pengaduan.baru'));
    }

    /* ============================== Eskalasi ============================== */

    #[Test]
    #[TestDox('FR-PGD-06: eskalasi menaikkan prioritas sekali saja, dan tidak mengubah status')]
    public function eskalasi_idempoten(): void
    {
        $pengaduan = $this->makeComplaint(['triage_due_at' => now()->subDays(3)]);

        $this->assertTrue($this->service()->escalate($pengaduan));
        $this->assertSame(Complaint::PRIORITY_HIGH, $pengaduan->fresh()?->priority);

        /*
         * Status TIDAK berubah. Eskalasi adalah peringatan, bukan keputusan
         * administratif: pengaduan yang terlambat tetap "baru" sampai
         * seseorang benar-benar mentriasenya.
         */
        $this->assertSame(Complaint::STATUS_NEW, $pengaduan->fresh()?->status);
        $this->assertSame(1, $this->notificationsOf($this->kasi, 'pengaduan.eskalasi'));

        // Dijalankan lagi: tidak ada peringatan kedua untuk keterlambatan sama.
        $this->assertFalse($this->service()->escalate($pengaduan->fresh() ?? $pengaduan));
        $this->assertSame(1, $this->notificationsOf($this->kasi, 'pengaduan.eskalasi'));
    }

    #[Test]
    #[TestDox('Perintah sid:eskalasi-pengaduan hanya menyentuh yang benar-benar terlambat')]
    public function perintah_eskalasi(): void
    {
        $terlambat = $this->makeComplaint(['triage_due_at' => now()->subWeek()]);
        $tepatWaktu = $this->makeComplaint(['triage_due_at' => now()->addWeek()]);

        $this->artisan('sid:eskalasi-pengaduan')
            ->expectsOutputToContain($terlambat->ticket_number)
            ->assertSuccessful();

        $this->assertSame(Complaint::PRIORITY_HIGH, $terlambat->fresh()?->priority);
        $this->assertSame(Complaint::PRIORITY_NORMAL, $tepatWaktu->fresh()?->priority);
    }

    #[Test]
    #[TestDox('Pengaduan selesai tidak pernah dieskalasi walau tenggatnya dulu terlampaui')]
    public function pengaduan_selesai_tidak_dieskalasi(): void
    {
        $selesai = $this->makeComplaint([
            'status' => Complaint::STATUS_DONE,
            'due_at' => now()->subMonth(),
            'resolution' => 'Sudah diperbaiki.',
            'resolved_at' => now()->subWeek(),
        ]);

        $this->assertFalse($this->service()->escalate($selesai));
        $this->assertSame(Complaint::PRIORITY_NORMAL, $selesai->fresh()?->priority);
    }

    /* =========================== Tindak lanjut ============================ */

    #[Test]
    #[TestDox('FR-PGD-05: tindak lanjut yang terlihat pelapor mengirim notifikasi, yang internal tidak')]
    public function tindak_lanjut_terlihat_dan_internal(): void
    {
        $scope = AccessScope::forUser($this->kasi);
        $pengaduan = $this->service()->accept(
            $scope,
            $this->makeComplaint(['reporter_user_id' => $this->warga->id]),
        );

        $this->service()->addFollowUp($scope, $pengaduan, 'Sudah ditinjau ke lokasi hari ini.');
        $this->service()->addFollowUp(
            $scope,
            $pengaduan,
            'Anggarannya belum ada; tunggu APBDes perubahan.',
            visibleToReporter: false,
        );

        // Satu dari transisi `diterima`, satu dari tindak lanjut terlihat.
        $this->assertSame(2, $this->notificationsOf($this->warga, 'pengaduan.status'));

        $terlihatPelapor = ComplaintAction::query()
            ->where('complaint_id', $pengaduan->id)
            ->where('is_visible_to_reporter', true)
            ->count();

        // Baris pembuatan + transisi diterima + satu tindak lanjut terlihat.
        $this->assertSame(3, $terlihatPelapor);
    }

    /* ======================== Publikasi ke portal ========================= */

    #[Test]
    #[TestDox('FR-PGD-07: pengaduan yang belum selesai tidak dapat dipublikasikan')]
    public function publikasi_hanya_setelah_selesai(): void
    {
        $scope = AccessScope::forUser($this->kasi);
        $pengaduan = $this->service()->accept($scope, $this->makeComplaint());

        try {
            $this->service()->setPublished($scope, $pengaduan, true);
            $this->fail('Pengaduan belum selesai seharusnya tidak dapat dipublikasikan.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sudah selesai', $e->getMessage());
        }

        $selesai = $this->service()->resolve($scope, $pengaduan, 'Jalan sudah ditambal.');

        $this->assertTrue($this->service()->setPublished($scope, $selesai, true)->is_published);
    }

    /* ============================== Pembantu ============================== */

    private function service(): ComplaintService
    {
        return app(ComplaintService::class);
    }

    private function repoFor(User $user): ComplaintRepository
    {
        return new ComplaintRepository(AccessScope::forUser($user));
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'category_code' => 'infrastruktur',
            'title' => 'Jalan rusak di depan balai desa',
            'body' => 'Lubang selebar satu meter sudah dua bulan dibiarkan.',
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function makeComplaint(array $attributes = []): Complaint
    {
        $pengaduan = $this->service()->create(
            AccessScope::forUser($this->warga),
            [...$this->input(), 'is_anonymous' => $attributes['is_anonymous'] ?? false],
        );

        unset($attributes['is_anonymous']);

        if ($attributes !== []) {
            $pengaduan->forceFill($attributes)->save();
        }

        // Notifikasi dari pembuatan dibersihkan agar hitungan di tiap uji
        // hanya mencakup apa yang uji itu sendiri picu.
        DatabaseNotification::query()->delete();

        return $pengaduan->refresh();
    }

    private function notificationsOf(User $user, string $category): int
    {
        return $user->notifications()
            ->whereJsonContains('data->kategori', $category)
            ->count();
    }

    private function firstRtOf(Region $dusun): Region
    {
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();

        return Region::query()->where('parent_id', $rw->id)->firstOrFail();
    }
}
