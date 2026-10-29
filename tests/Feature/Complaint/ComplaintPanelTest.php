<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Auth\AccessScope;
use App\Http\Resources\ComplaintPresenter;
use App\Models\Complaint;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Complaint\ComplaintService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Panel petugas pengaduan — UC-14, FR-PGD-03 s.d. FR-PGD-07.
 *
 * Yang dijaga kelas ini:
 *   1. Matriks RBAC docs/03 §4.9 benar-benar berlaku di rute: Kepala Desa
 *      dan Kepala Dusun MEMBACA, tidak menindaklanjuti.
 *   2. Pengaduan di luar lingkup menghasilkan 404, bukan 403 (docs/06 §4) —
 *      termasuk pada rute yang MENGUBAH, bukan hanya yang membaca.
 *   3. `tracking_code` tidak pernah sampai ke layar petugas. Ia rahasia milik
 *      pelapor; petugas yang dapat membacanya dapat membuka pengaduan anonim
 *      siapa pun lewat halaman publik.
 *   4. Ringkasan yang terbit ke portal tidak membawa lokasi maupun pelapor.
 */
final class ComplaintPanelTest extends TestCase
{
    use DatabaseTransactions;

    private User $kasi;

    private User $kades;

    private User $kadus;

    private User $warga;

    private Region $dusunKrajan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->dusunKrajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();

        $this->kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);
        $this->kades = $this->userWithRole(Role::KEPALA_DESA);
        $this->kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);
        $this->warga = $this->userWithRole(Role::WARGA);
    }

    /* ============================== Antrean =============================== */

    #[Test]
    #[TestDox('Antrean bawaannya hanya memuat pengaduan yang masih terbuka')]
    public function antrean_bawaan_hanya_terbuka(): void
    {
        $terbuka = $this->makeComplaint();
        $selesai = $this->makeComplaint();
        $this->resolve($selesai);

        $this->actingAs($this->kasi)->get('/pengaduan/antrean')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pengaduan/Index')
                ->has('pengaduan.data', 1)
                ->where('pengaduan.data.0.ticket_number', $terbuka->ticket_number)
                ->where('filters.status', 'terbuka'));

        // Dan seluruhnya terlihat bila diminta — yang dibatasi hanya bawaannya.
        $this->actingAs($this->kasi)->get('/pengaduan/antrean?status=')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('pengaduan.data', 2));
    }

    #[Test]
    #[TestDox('Kode pelacakan tidak pernah sampai ke layar petugas, baik di antrean maupun detail')]
    public function kode_pelacakan_tidak_sampai_ke_petugas(): void
    {
        $pengaduan = $this->makeComplaint();

        foreach ([
            '/pengaduan/antrean',
            '/pengaduan/antrean/'.$pengaduan->id,
        ] as $url) {
            $response = $this->actingAs($this->kasi)->get($url)->assertOk();

            /*
             * Diperiksa pada SELURUH badan balasan, bukan hanya pada satu
             * kunci payload: kebocoran seperti ini justru terjadi lewat kunci
             * yang tidak diduga — satu `...$complaint->toArray()` sudah cukup.
             */
            $response->assertDontSee($pengaduan->tracking_code, false);
        }
    }

    #[Test]
    #[TestDox('Nomor telepon pelapor hanya di detail, tidak di baris antrean')]
    public function telepon_hanya_di_detail(): void
    {
        $pengaduan = $this->makeComplaint(['reporter_phone' => '081299887766']);

        /*
         * Daftar antrean dibuka di layar yang sering terlihat orang lain di
         * meja pelayanan. Nomor telepon pelapor pengaduan tentang tetangganya
         * tidak perlu terbaca siapa pun yang lewat.
         */
        $this->actingAs($this->kasi)->get('/pengaduan/antrean')
            ->assertOk()
            ->assertDontSee('081299887766', false);

        $this->actingAs($this->kasi)->get('/pengaduan/antrean/'.$pengaduan->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pengaduan.reporter_phone', '081299887766'));
    }

    #[Test]
    #[TestDox('Detail membedakan batas triase dari batas penyelesaian, dan tidak menampilkan yang belum ada')]
    public function detail_memisahkan_kedua_tenggat(): void
    {
        $pengaduan = $this->makeComplaint();

        /*
         * Selagi `baru`, batas penyelesaian BELUM ADA — ia baru dihitung saat
         * triase. Baris antrean menampilkan satu tanggal (yang sedang
         * ditunggu), sedangkan detail menampilkan dua baris bernama sendiri;
         * mengirim tenggat-yang-berlaku ke keduanya membuat baris "Batas
         * penyelesaian" memperlihatkan tanggal triase.
         */
        $this->actingAs($this->kasi)->get('/pengaduan/antrean/'.$pengaduan->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pengaduan.due_at', null)
                ->whereNot('pengaduan.triage_due_at', null));

        $this->accept($pengaduan);

        $this->actingAs($this->kasi)->get('/pengaduan/antrean/'.$pengaduan->id)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $p = $page->toArray()['props']['pengaduan'];

                $this->assertNotNull($p['due_at']);
                $this->assertNotNull($p['triage_due_at']);
                $this->assertNotSame($p['due_at'], $p['triage_due_at']);
            });
    }

    /* ========================== Matriks RBAC ============================== */

    #[Test]
    #[TestDox('Kepala Desa & Kepala Dusun membaca pengaduan, tetapi tidak dapat mengubah statusnya')]
    public function peran_peninjau_tidak_dapat_menindaklanjuti(): void
    {
        $pengaduan = $this->makeComplaint(['region_id' => $this->dusunKrajan->id]);

        foreach ([$this->kades, $this->kadus] as $peninjau) {
            $this->actingAs($peninjau)->get('/pengaduan/antrean')->assertOk();
            $this->actingAs($peninjau)->get('/pengaduan/antrean/'.$pengaduan->id)->assertOk();

            $this->actingAs($peninjau)->post('/pengaduan/antrean/'.$pengaduan->id.'/status', [
                'status' => Complaint::STATUS_ACCEPTED,
            ])->assertForbidden();

            $this->actingAs($peninjau)->post('/pengaduan/antrean/'.$pengaduan->id.'/tugaskan', [
                'assigned_to' => $this->kasi->id,
            ])->assertForbidden();
        }

        $this->assertSame(Complaint::STATUS_NEW, $pengaduan->fresh()?->status);
    }

    #[Test]
    #[TestDox('KEP-012: layar mengatakan mengapa tombol tindak lanjut tidak ada, bukan menghilangkannya diam-diam')]
    public function penolakan_izin_terlihat_di_antarmuka(): void
    {
        $pengaduan = $this->makeComplaint();

        $this->actingAs($this->kades)->get('/pengaduan/antrean/'.$pengaduan->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('izin.tindaklanjut', false)
                ->where('izin.tugaskan', false));

        $this->actingAs($this->kasi)->get('/pengaduan/antrean/'.$pengaduan->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('izin.tindaklanjut', true)
                ->where('izin.tugaskan', true));
    }

    #[Test]
    #[TestDox('Warga ditolak dari panel petugas, termasuk untuk pengaduannya sendiri')]
    public function warga_ditolak_dari_panel_petugas(): void
    {
        $milikWarga = $this->makeComplaint();

        /*
         * Warga MEMANG memegang `pengaduan.lihat` — matriks RBAC docs/03 §4.9
         * memberinya "C,R*", yaitu membuat dan membaca miliknya sendiri. Izin
         * itu dilayani portal warga (`/warga/pengaduan`), BUKAN antrean
         * petugas.
         *
         * Sebelum middleware `petugas` dipasang, izin itu cukup untuk membuka
         * layar petugas atas pengaduannya sendiri — lengkap dengan catatan
         * koordinasi internal yang ditandai tidak terlihat pelapor. Lihat
         * EnsureStaffQueue dan ComplaintPenetrationTest.
         */
        $this->assertTrue($this->warga->hasPermission('pengaduan.lihat'));

        $this->actingAs($this->warga)->get('/pengaduan/antrean')->assertForbidden();
        $this->actingAs($this->warga)
            ->get('/pengaduan/antrean/'.$milikWarga->id)
            ->assertForbidden();

        $this->actingAs($this->warga)
            ->post('/pengaduan/antrean/'.$milikWarga->id.'/status', [
                'status' => Complaint::STATUS_ACCEPTED,
            ])->assertForbidden();

        $this->assertSame(Complaint::STATUS_NEW, $milikWarga->fresh()?->status);
    }

    #[Test]
    #[TestDox('Pengaduan dusun lain menghasilkan 404 juga pada rute yang MENGUBAH, bukan hanya yang membaca')]
    public function luar_lingkup_404_termasuk_pada_perubahan(): void
    {
        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
        $pengaduanLain = $this->makeComplaint(['region_id' => $dusunLain->id]);

        /*
         * Kepala Dusun diberi izin tindak lanjut di sini supaya yang diuji
         * benar-benar LINGKUP, bukan izin: tanpa itu, 403 dari middleware
         * akan menutupi pertanyaan apakah rute pengubah menghormati lingkup.
         *
         * Yang dijaga: rute pengubah tidak boleh membocorkan keberadaan
         * pengaduan di dusun lain lewat jawaban yang berbeda (docs/06 §4).
         */
        $tindakLanjut = Permission::query()
            ->where('code', 'pengaduan.tindaklanjut')->firstOrFail();
        Role::query()->where('code', Role::KEPALA_DUSUN)->firstOrFail()
            ->permissions()->syncWithoutDetaching([$tindakLanjut->id]);

        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $this->dusunKrajan);

        $this->actingAs($kadus)->get('/pengaduan/antrean/'.$pengaduanLain->id)->assertNotFound();

        $this->actingAs($kadus)->post('/pengaduan/antrean/'.$pengaduanLain->id.'/status', [
            'status' => Complaint::STATUS_ACCEPTED,
        ])->assertNotFound();

        $this->actingAs($kadus)->post('/pengaduan/antrean/'.$pengaduanLain->id.'/tindak-lanjut', [
            'note' => 'Sudah dicek.',
        ])->assertNotFound();

        $this->assertSame(Complaint::STATUS_NEW, $pengaduanLain->fresh()?->status);
    }

    /* =========================== Tindak lanjut ============================ */

    #[Test]
    #[TestDox('Alur lengkap petugas: triase, tugaskan, tindak lanjut, selesai')]
    public function alur_lengkap_petugas(): void
    {
        $pengaduan = $this->makeComplaint();
        $kasiLain = $this->userWithRole(Role::KASI_PELAYANAN);

        $this->actingAs($this->kasi)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/status', [
                'status' => Complaint::STATUS_ACCEPTED,
                'note' => 'Diterima, akan ditinjau ke lokasi.',
            ])->assertRedirect();

        $this->actingAs($this->kasi)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/tugaskan', [
                'assigned_to' => $kasiLain->id,
            ])->assertRedirect();

        $this->actingAs($kasiLain)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/tindak-lanjut', [
                'note' => 'Sudah ditinjau; material sudah dipesan.',
            ])->assertRedirect();

        $this->actingAs($kasiLain)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/status', [
                'status' => Complaint::STATUS_DONE,
                'note' => 'Lampu sudah diganti pada 25 Oktober.',
            ])->assertRedirect();

        $pengaduan->refresh();

        $this->assertSame(Complaint::STATUS_DONE, $pengaduan->status);
        $this->assertSame($kasiLain->id, $pengaduan->assigned_to);
        $this->assertSame('Lampu sudah diganti pada 25 Oktober.', $pengaduan->resolution);
        $this->assertNotNull($pengaduan->resolved_at);
    }

    #[Test]
    #[TestDox('FR-PGD-03: menyelesaikan tanpa narasi hasil ditolak, dan pesannya muncul pada isian yang dipakai formulir')]
    public function selesai_tanpa_narasi_ditolak(): void
    {
        $pengaduan = $this->makeComplaint();
        $this->accept($pengaduan);

        $response = $this->actingAs($this->kasi)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/status', [
                'status' => Complaint::STATUS_DONE,
                'note' => '   ',
            ]);

        /*
         * Kuncinya `note`, bukan `resolution`. Pesan validasi yang memakai
         * nama KOLOM dan bukan nama ISIAN tidak ditampilkan di mana pun —
         * petugas hanya melihat tombol yang ditekan lalu tidak terjadi apa-apa.
         */
        $response->assertSessionHasErrors('note');

        $this->assertSame(Complaint::STATUS_ACCEPTED, $pengaduan->fresh()?->status);
    }

    #[Test]
    #[TestDox('Catatan internal tercatat di riwayat petugas lengkap dengan penandanya')]
    public function catatan_internal_bertanda(): void
    {
        $pengaduan = $this->makeComplaint();
        $this->accept($pengaduan);

        $this->actingAs($this->kasi)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/tindak-lanjut', [
                'note' => 'Dugaan awal: kabel dicuri.',
                'is_visible_to_reporter' => false,
            ])->assertRedirect();

        $this->actingAs($this->kasi)->get('/pengaduan/antrean/'.$pengaduan->id)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $timeline = $page->toArray()['props']['pengaduan']['timeline'];
                $internal = array_values(array_filter(
                    $timeline,
                    fn (array $s): bool => $s['is_visible_to_reporter'] === false,
                ));

                $this->assertNotSame([], $internal);
                $this->assertSame('Dugaan awal: kabel dicuri.', $internal[0]['note']);
                $this->assertNotNull($internal[0]['actor_name']);
            });
    }

    /* ========================= Publikasi portal =========================== */

    #[Test]
    #[TestDox('FR-PGD-07: ringkasan terbit tanpa lokasi, koordinat, maupun pelapor')]
    public function ringkasan_terbit_tanpa_identitas(): void
    {
        $pengaduan = $this->makeComplaint([
            'location_text' => 'Depan rumah Pak Sumadi',
            'reporter_phone' => '081299887766',
        ]);
        $this->resolve($pengaduan);

        $this->actingAs($this->kasi)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/terbitkan', ['is_published' => true])
            ->assertRedirect();

        $this->assertTrue($pengaduan->fresh()?->is_published);

        $ringkasan = ComplaintPresenter::publicSummary($pengaduan->fresh() ?? $pengaduan);
        $serialized = json_encode($ringkasan) ?: '';

        /*
         * "Jalan rusak di depan rumah Pak Sumadi" yang terbit di portal desa
         * menunjuk satu rumah tertentu. Itu bukan keterbukaan informasi
         * melainkan penyingkapan.
         */
        $this->assertStringNotContainsString('Pak Sumadi', $serialized);
        $this->assertStringNotContainsString('081299887766', $serialized);
        $this->assertArrayNotHasKey('location_text', $ringkasan);
        $this->assertArrayNotHasKey('latitude', $ringkasan);
    }

    #[Test]
    #[TestDox('FR-PGD-07: ringkasan yang terbit benar-benar tampil di portal publik, tanpa lokasi')]
    public function ringkasan_tampil_di_portal(): void
    {
        $terbit = $this->makeComplaint(['location_text' => 'Depan rumah Pak Sumadi']);
        $this->resolve($terbit);
        $this->service()->setPublished(
            AccessScope::forUser($this->kasi),
            $terbit->fresh() ?? $terbit,
            true,
        );

        $belumTerbit = $this->makeComplaint(['title' => 'Saluran air tersumbat di RT 004']);
        $this->resolve($belumTerbit);

        $response = $this->get('/kontak')->assertOk();

        $response->assertSee($terbit->title, false);
        $response->assertSee('Sudah ditangani.', false);

        /*
         * Yang TIDAK terbit sama pentingnya: pengaduan selesai yang tidak
         * dipilih petugas, dan lokasi yang menunjuk satu rumah tertentu.
         */
        $response->assertDontSee('Saluran air tersumbat di RT 004', false);
        $response->assertDontSee('Pak Sumadi', false);
    }

    #[Test]
    #[TestDox('Pengaduan yang belum selesai tidak dapat dipublikasikan lewat rute')]
    public function belum_selesai_tidak_dapat_terbit(): void
    {
        $pengaduan = $this->makeComplaint();

        $this->actingAs($this->kasi)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/terbitkan', ['is_published' => true])
            ->assertSessionHasErrors('is_published');

        $this->assertFalse($pengaduan->fresh()?->is_published);
    }

    /* ============================== Pembantu ============================== */

    private function service(): ComplaintService
    {
        return app(ComplaintService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function makeComplaint(array $attributes = []): Complaint
    {
        $pengaduan = $this->service()->create(AccessScope::forUser($this->warga), [
            'category_code' => 'infrastruktur',
            'title' => 'Lampu jalan mati di RT 002',
            'body' => 'Sudah dua bulan lampu jalan di depan pos ronda mati.',
        ]);

        if ($attributes !== []) {
            $pengaduan->forceFill($attributes)->save();
        }

        return $pengaduan->refresh();
    }

    private function accept(Complaint $complaint): Complaint
    {
        return $this->service()->accept(AccessScope::forUser($this->kasi), $complaint);
    }

    private function resolve(Complaint $complaint): Complaint
    {
        return $this->service()->resolve(
            AccessScope::forUser($this->kasi),
            $this->accept($complaint),
            'Sudah ditangani.',
        );
    }
}
