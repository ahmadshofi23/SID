<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Auth\AccessScope;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\Family;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Services\Complaint\ComplaintService;
use App\Support\NameNormalizer;
use App\Support\Pii\PiiCipher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Kanal pengaduan yang menghadap warga — FR-PGD-01, FR-PGD-02, UC-13.
 *
 * Yang dijaga kelas ini, di luar "formulirnya jalan":
 *   1. Pengaduan dapat dikirim TANPA akun (matriks RBAC §4.9: PB "C(anonim)").
 *   2. Kode pelacakan keluar TEPAT SEKALI — pada tanda terima — dan tidak
 *      pernah ikut ke payload lain, termasuk ke halaman pelacakan itu sendiri.
 *   3. Kode pelacakan tidak pernah masuk audit log, bahkan saat percobaan
 *      gagal. Pelacakan anonim yang berhasil juga tidak dicatat sebagai
 *      tautan antara sebuah akun dan sebuah pengaduan anonim.
 *   4. Halaman "pengaduan saya" mengatakan bahwa pengaduan anonim tidak
 *      tampil di sana, sehingga warga tidak menyangka laporannya hilang.
 *   5. Janji di portal Blade benar-benar menuju kanal yang ada.
 */
final class PublicComplaintTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    /* ============================== Formulir ============================== */

    #[Test]
    #[TestDox('Tamu tanpa akun dapat membuka formulir dan mengirim pengaduan')]
    public function tamu_dapat_mengirim_pengaduan(): void
    {
        $this->get('/pengaduan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Publik/Pengaduan')
                // Nilai awal pelapor null bagi tamu: tidak ada yang diisikan.
                ->where('pelapor', null)
                ->has('kategori')
                ->has('wilayah'));

        $response = $this->post('/pengaduan', $this->input());

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Publik/PengaduanTerkirim')
            ->has('tanda_terima.tracking_code')
            ->has('tanda_terima.ticket_number'));

        $this->assertSame(1, Complaint::query()->count());
    }

    #[Test]
    #[TestDox('Warga yang sudah masuk mendapat nama & telepon sebagai nilai awal, bukan kolom terkunci')]
    public function nilai_awal_untuk_warga_yang_masuk(): void
    {
        $warga = $this->userWithRole(Role::WARGA);
        $warga->forceFill(['phone' => '081200000001'])->save();

        $this->actingAs($warga)->get('/pengaduan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pelapor.name', $warga->name)
                ->where('pelapor.phone', '081200000001'));

        /*
         * Dan ia TETAP dapat melapor anonim. Formulir yang memaksa nama
         * terpasang bagi pengguna yang sudah masuk berarti janji anonim
         * FR-PGD-02 hanya berlaku bagi orang yang belum mendaftar.
         */
        $this->actingAs($warga)->post('/pengaduan', [
            ...$this->input(),
            'is_anonymous' => true,
        ])->assertOk();

        $pengaduan = Complaint::query()->firstOrFail();

        $this->assertTrue($pengaduan->is_anonymous);
        $this->assertNull($pengaduan->reporter_user_id);
    }

    #[Test]
    #[TestDox('FR-PGD-08: judul & isi yang terlalu singkat ditolak dengan pesan yang menjelaskan sebabnya')]
    public function isi_terlalu_singkat_ditolak(): void
    {
        $response = $this->post('/pengaduan', [
            'category_code' => 'infrastruktur',
            'title' => 'Rusak',
            'body' => 'Tolong.',
        ]);

        $response->assertSessionHasErrors(['title', 'body']);

        /*
         * Pesannya diperiksa isinya, bukan hanya keberadaannya: pengaduan
         * "Jalan rusak" tanpa keterangan apa pun harus dikembalikan, dan
         * pesan yang hanya berbunyi "minimal 30 karakter" membuat warga
         * menambah kata tanpa menambah keterangan.
         */
        $this->assertStringContainsString('rinci', $this->errorMessage($response, 'body'));
        $this->assertStringContainsString('apa dan di mana', $this->errorMessage($response, 'title'));
    }

    #[Test]
    #[TestDox('Kategori di luar tabel referensi ditolak, bukan disimpan apa adanya')]
    public function kategori_tak_dikenali_ditolak(): void
    {
        $this->post('/pengaduan', [...$this->input(), 'category_code' => 'bukan_kategori'])
            ->assertSessionHasErrors('category_code');

        $this->assertSame(0, Complaint::query()->count());
    }

    #[Test]
    #[TestDox('Wilayah yang disebut pelapor tersimpan, sehingga pengaduan terlihat perangkat dusun setempat')]
    public function wilayah_tersimpan(): void
    {
        $rt = $this->firstRt();

        $this->post('/pengaduan', [...$this->input(), 'region_id' => $rt->id])->assertOk();

        $this->assertSame($rt->id, Complaint::query()->firstOrFail()->region_id);
    }

    /* ============================== Pelacakan ============================= */

    #[Test]
    #[TestDox('FR-PGD-02: kode pelacakan membuka status & tindak lanjut tanpa perlu masuk')]
    public function pelacakan_dengan_kode(): void
    {
        $pengaduan = $this->makeComplaint();

        $this->post('/pengaduan/lacak', ['tracking_code' => $pengaduan->tracking_code])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Publik/LacakPengaduan')
                ->where('found', true)
                ->where('pengaduan.ticket_number', $pengaduan->ticket_number)
                /*
                 * Kode pelacakan TIDAK ikut ke payload halaman pelacakan.
                 * Ia rahasia; halaman yang menampilkannya kembali berarti
                 * kode itu tersimpan di riwayat peramban perangkat yang di
                 * desa sering dipakai bergantian.
                 */
                ->missing('pengaduan.tracking_code'));
    }

    #[Test]
    #[TestDox('Kode huruf kecil tetap diterima — warga menyalin dari catatan tangan')]
    public function kode_tidak_peka_huruf_besar(): void
    {
        $pengaduan = $this->makeComplaint();

        $this->post('/pengaduan/lacak', [
            'tracking_code' => strtolower($pengaduan->tracking_code),
        ])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('found', true));
    }

    #[Test]
    #[TestDox('Kode salah menghasilkan satu jawaban seragam, dan kodenya tidak pernah masuk audit log')]
    public function kode_salah_tidak_bocor_ke_audit_log(): void
    {
        $this->makeComplaint();

        $this->post('/pengaduan/lacak', ['tracking_code' => 'KODESALAHSEKALI1'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('found', true === false)
                ->missing('pengaduan'));

        $log = AuditLog::query()->where('action', 'complaint.tracking_failed')->firstOrFail();

        /*
         * Percobaan gagal DICATAT — rentetannya adalah satu-satunya tanda
         * terlihat bahwa seseorang sedang menebak. Tetapi kode yang dicoba
         * TIDAK, berbeda dari nomor tiket pada pelacakan surat: nomor tiket
         * adalah pengenal administratif, sedangkan kode pelacakan adalah
         * rahasia — dan rahasia yang salah ketik satu huruf lalu tersimpan di
         * audit log adalah rahasia yang bocor ke tempat yang dibaca Admin
         * Desa, pihak yang paling sering diadukan.
         */
        $this->assertStringNotContainsString(
            'KODESALAHSEKALI1',
            json_encode([$log->context, $log->entity_id]) ?: '',
        );
    }

    #[Test]
    #[TestDox('RULE-09: audit log tidak menautkan akun mana pun ke pengaduan anonim')]
    public function audit_log_tidak_menautkan_akun_ke_pengaduan_anonim(): void
    {
        $warga = $this->userWithRole(Role::WARGA);

        /*
         * Jalur NYATANYA: warga yang sedang masuk mencentang "anonim". Inilah
         * jalan pintas RULE-09 yang paling mudah terlewat — AuditLogger yang
         * dipanggil dengan model menyimpan entity_id BESERTA actor_id,
         * actor_name, dan actor_roles, sehingga satu baris audit log berisi
         * tepat tautan yang dilarang ada di tabel pengaduan, lengkap dengan
         * namanya. CHECK chk_complaints_anon tidak menjaga audit log.
         */
        $this->actingAs($warga)
            ->post('/pengaduan', [...$this->input(), 'is_anonymous' => true])
            ->assertOk();

        $anonim = Complaint::query()->firstOrFail();

        $this->assertSame(
            0,
            AuditLog::query()->where('entity_id', $anonim->id)->count(),
            'KEBOCORAN RULE-09: audit log menyebut pengaduan anonim beserta pelakunya.'
        );

        /*
         * Fakta bahwa ada pengaduan anonim TETAP dicatat — tanpa itu, volume
         * dan penyalahgunaan kanal ini tidak dapat dipantau sama sekali. Yang
         * hilang hanyalah kemampuan menautkannya ke seseorang.
         */
        $jejak = AuditLog::query()->where('action', 'complaint.created_anonymous')->firstOrFail();

        $this->assertNull($jejak->entity_id);
        $this->assertNull($jejak->actor_id);
        $this->assertSame('sistem', $jejak->actor_name);

        // Dan pengaduan BERNAMA tetap tercatat utuh: yang dibedakan adalah
        // keanoniman, bukan pencatatannya secara umum.
        $this->post('/pengaduan', $this->input())->assertOk();

        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'complaint.created')->count(),
        );
    }

    #[Test]
    #[TestDox('RULE-09: alamat IP pelapor tidak pernah tersimpan mentah, sehingga tidak dapat di-JOIN dengan jejak login')]
    public function alamat_ip_tidak_tersimpan_mentah(): void
    {
        $ip = '203.0.113.42';

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/pengaduan', [...$this->input(), 'is_anonymous' => true])
            ->assertOk();

        $anonim = Complaint::query()->firstOrFail();

        /*
         * Audit log mencatat `ip_address` setiap kali seseorang masuk. Alamat
         * IP mentah di tabel pengaduan karena itu dapat di-JOIN dengan baris
         * login, dan pengaduan anonim tentang pungutan liar menjadi bernama
         * kembali lewat dua kueri — oleh orang yang justru paling sering
         * diadukan.
         */
        $this->assertNotNull($anonim->submitted_ip_hash);
        $this->assertNotSame($ip, $anonim->submitted_ip_hash);
        $this->assertSame(
            app(PiiCipher::class)->hash($ip),
            $anonim->submitted_ip_hash,
            'Sidik jari IP harus HMAC ber-pepper, bukan hash biasa: ruang IPv4 '
            .'hanya 4 miliar nilai dan tabel pelanginya dapat dibangun dalam menit.'
        );
    }

    #[Test]
    #[TestDox('Tindak lanjut internal tidak ikut ke halaman pelacakan pelapor')]
    public function catatan_internal_tidak_terlihat_pelapor(): void
    {
        $pengaduan = $this->makeComplaint();
        $kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);
        $service = app(ComplaintService::class);
        $scope = AccessScope::forUser($kasi);

        $pengaduan = $service->accept($scope, $pengaduan);
        $service->addFollowUp($scope, $pengaduan, 'Sudah ditinjau ke lokasi.');
        $service->addFollowUp(
            $scope,
            $pengaduan,
            'Dugaan awal: kabel dicuri. Jangan disebut ke pelapor dulu.',
            visibleToReporter: false,
        );

        $this->post('/pengaduan/lacak', ['tracking_code' => $pengaduan->tracking_code])
            ->assertOk()
            ->assertDontSee('kabel dicuri', false)
            ->assertSee('Sudah ditinjau ke lokasi', false);
    }

    /* =========================== Portal warga ============================= */

    #[Test]
    #[TestDox('Pengaduan saya hanya memuat milik sendiri, dan layarnya mengatakan yang anonim tidak tampil')]
    public function pengaduan_saya(): void
    {
        $warga = $this->linkedCitizen('3201010101900101', 'Warga Satu');
        $wargaLain = $this->linkedCitizen('3201010101900102', 'Warga Dua');

        $milikSendiri = $this->makeComplaint(reporter: $warga);
        $milikOrangLain = $this->makeComplaint(reporter: $wargaLain);
        $anonimMilikSendiri = $this->makeComplaint(['is_anonymous' => true], reporter: $warga);

        $this->actingAs($warga)->get('/warga/pengaduan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Warga/Pengaduan/Index')
                ->has('pengaduan.data', 1)
                ->where('pengaduan.data.0.ticket_number', $milikSendiri->ticket_number));

        /*
         * Kalimat peringatannya diuji dari BERKAS KOMPONEN, bukan dari badan
         * balasan HTTP: halaman Inertia hanya mengirim JSON props, sehingga
         * teks templat Vue tidak pernah muncul di balasan yang dilihat uji.
         *
         * Ujinya lemah dengan sadar, dan tetap berharga: yang dijaganya bukan
         * tata letak melainkan agar kalimat itu tidak terhapus tanpa sengaja.
         * Pengaduan anonim tidak akan pernah muncul di daftar ini — itu
         * konsekuensi langsung RULE-09 — dan tanpa penjelasannya, warga yang
         * melapor anonim lalu membuka halaman ini akan menyimpulkan laporannya
         * hilang dan berhenti memakai kanal pengaduan sama sekali.
         */
        $this->assertStringContainsString(
            'Pengaduan anonim tidak tampil di sini',
            (string) file_get_contents(resource_path('js/Pages/Warga/Pengaduan/Index.vue')),
            'Peringatan tentang pengaduan anonim hilang dari halaman "Pengaduan Saya". '
            .'Tanpa itu, warga yang melapor anonim akan menyangka laporannya hilang.'
        );

        // Pengaduan orang lain: 404, bukan 403 (docs/06 §4).
        $this->actingAs($warga)->get('/warga/pengaduan/'.$milikOrangLain->id)->assertNotFound();
        $this->actingAs($warga)->get('/warga/pengaduan/'.$anonimMilikSendiri->id)->assertNotFound();
        $this->actingAs($warga)->get('/warga/pengaduan/'.$milikSendiri->id)->assertOk();
    }

    /* ============================ Portal Blade ============================ */

    #[Test]
    #[TestDox('Janji di portal publik menuju kanal yang benar-benar ada, bukan "sedang disiapkan"')]
    public function portal_menautkan_kanal_pengaduan(): void
    {
        foreach (['/', '/kontak'] as $url) {
            $response = $this->get($url)->assertOk();

            $response->assertSee('/pengaduan', false);
            $response->assertDontSee('sedang disiapkan', false);
        }

        // Pilihan anonim disebut di halaman kontak, bukan hanya di dalam
        // formulirnya: orang yang ingin melaporkan pungutan liar harus tahu
        // sebelum menekan tautannya.
        $this->get('/kontak')->assertSee('tanpa menyebutkan nama', false);
    }

    #[Test]
    #[TestDox('Formulir pengaduan TIDAK masuk peta situs — ia halaman Inertia yang kosong tanpa JavaScript')]
    public function formulir_pengaduan_tidak_di_peta_situs(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent() ?: '';

        $this->assertStringNotContainsString('/pengaduan</loc>', $sitemap);
        $this->assertStringNotContainsString('/pengaduan/lacak', $sitemap);

        // Tetapi jalur masuknya tetap terbaca perayap, lewat halaman Blade
        // yang memang terindeks.
        $this->assertStringContainsString('/kontak', $sitemap);
    }

    /* ============================== Pembantu ============================== */

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'category_code' => 'infrastruktur',
            'title' => 'Lampu jalan mati di RT 002',
            'body' => 'Sudah dua bulan lampu jalan di depan pos ronda mati, '
                .'sehingga warga yang pulang malam harus berjalan gelap.',
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function makeComplaint(array $attributes = [], ?User $reporter = null): Complaint
    {
        $scope = $reporter === null
            ? AccessScope::guest()
            : AccessScope::forUser($reporter);

        return app(ComplaintService::class)->create($scope, [
            ...$this->input(),
            ...$attributes,
        ]);
    }

    /** Akun warga yang tertaut ke penduduk — prasyarat middleware `warga`. */
    private function linkedCitizen(string $nik, string $name): User
    {
        $rt = $this->firstRt();

        $family = new Family;
        $family->setKkNumber('320101'.substr($nik, -10));
        $family->forceFill(['region_id' => $rt->id, 'address' => 'Alamat Uji'])->save();

        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1990-01-01',
            'gender' => 'L',
            'family_relation_code' => 'kepala_keluarga',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $this->userWithRole(Role::WARGA, residentId: $resident->id);
    }

    private function firstRt(): Region
    {
        return Region::query()->where('level', 'rt')->orderBy('path')->firstOrFail();
    }
}
