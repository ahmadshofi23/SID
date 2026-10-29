<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditLogReader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Halaman Log Aktivitas — FR-ADM-05, UC-19, BR-41.
 *
 * Yang dijaga kelas ini, berurutan menurut akibat bila ia gagal:
 *
 *   1. **Alamat IP pada baris tanpa pelaku tidak pernah utuh di layar.**
 *      Ini bukan soal tampilan: KEP-018 menyimpan IP pelapor pengaduan
 *      sebagai hash justru karena alamat mentah dapat di-JOIN dengan audit
 *      log, dan halaman ini mengubah JOIN itu menjadi dua kali menyaring.
 *      Lihat KEP-021.
 *   2. **Ekspor menuntut izinnya sendiri.** Kepala Desa dan Pengawas membaca
 *      di layar; hanya Admin Desa yang boleh mengeluarkan berkasnya.
 *   3. **Ekspor audit log ikut tercatat di audit log**, beserta jumlah baris.
 *   4. **Tidak ada COUNT(*) pada pembukaan halaman** (docs/23 §7 butir 4) —
 *      dijaga dengan memeriksa kueri yang benar-benar dijalankan, bukan
 *      dengan membaca kodenya.
 *   5. Saringan benar-benar menyaring, dan kursor tidak melompati baris yang
 *      waktunya sama persis.
 */
final class AuditLogPageTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $kades;

    private User $pengawas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->admin = $this->userWithRole(Role::ADMIN_DESA);
        $this->kades = $this->userWithRole(Role::KEPALA_DESA);
        $this->pengawas = $this->userWithRole(Role::PENGAWAS);
    }

    /* ============================== Otorisasi ============================= */

    #[Test]
    #[TestDox('Admin Desa, Kepala Desa, dan Pengawas dapat membuka halaman')]
    public function pemegang_izin_lihat_dapat_membuka(): void
    {
        foreach ([$this->admin, $this->kades, $this->pengawas] as $user) {
            $this->actingAs($user)
                ->get('/pengaturan/log-aktivitas')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Pengaturan/LogAktivitas'));
        }
    }

    #[Test]
    #[TestDox('Operator tanpa izin audit.lihat ditolak')]
    public function tanpa_izin_ditolak(): void
    {
        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->get('/pengaturan/log-aktivitas')
            ->assertForbidden();
    }

    #[Test]
    #[TestDox('Kepala Desa dan Pengawas MEMBACA tetapi tidak dapat mengekspor')]
    public function ekspor_menuntut_izin_sendiri(): void
    {
        /*
         * Inti uji ini bukan "ada dua izin", melainkan bahwa keduanya BERBEDA
         * pemegangnya. Kalau suatu saat `audit.ekspor` ikut diberikan kepada
         * peran yang hanya boleh membaca, uji ini gagal — dan itu memang
         * keputusan yang harus dibuat sadar, bukan lewat satu baris di
         * RoleSeeder.
         */
        foreach ([$this->kades, $this->pengawas] as $user) {
            $this->actingAs($user)
                ->get('/pengaturan/log-aktivitas')
                ->assertOk();

            $this->actingAs($user)
                ->get('/pengaturan/log-aktivitas/ekspor')
                ->assertForbidden();
        }

        $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas/ekspor')
            ->assertOk();
    }

    #[Test]
    #[TestDox('Daftar pelaku dikirim dalam bentuk yang <SelectField> benar-benar baca')]
    public function daftar_pelaku_berbentuk_value_label(): void
    {
        /*
         * Uji ini ada karena kesalahannya TIDAK TERLIHAT di mana pun.
         *
         * `SelectField` menerima `Option[]`, yaitu `{value, label}`. Daftar
         * pelaku semula dikirim sebagai `{id, name}` — dan halamannya tetap
         * dimuat tanpa satu pun galat, di peramban maupun di vue-tsc, hanya
         * dengan saringan pelaku yang KOSONG. Tipe Inertia tidak diperiksa
         * saat berjalan, jadi tidak ada lapis lain yang akan menangkapnya;
         * yang menemukannya adalah membuka halamannya dan melihat.
         */
        $this->logger()->log('resident.updated', actor: $this->admin);

        $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pelaku', 1)
                ->where('pelaku.0.value', $this->admin->id)
                ->where('pelaku.0.label', $this->admin->name));
    }

    /* ======================= RULE-09: alamat IP ========================== */

    #[Test]
    #[TestDox('Baris yang dicatat tanpa pelaku tidak pernah membawa alamat IP')]
    public function baris_tanpa_pelaku_tidak_pernah_membawa_alamat_ip(): void
    {
        /*
         * INI invariannya, dan ia dijaga pada PENULISAN — bukan pada
         * tampilan.
         *
         * KEP-018 menyimpan alamat IP pelapor pengaduan sebagai hash justru
         * karena alamat mentah dapat di-JOIN dengan audit log untuk menunjuk
         * pelapor anonim. `AuditLogger::logWithoutActor()` karena itu tidak
         * mengisi `ip_address` sama sekali. Selama itu benar, halaman Log
         * Aktivitas tidak dapat membocorkan apa pun — ia menampilkan apa yang
         * ada, dan yang ada memang bukan alamat.
         *
         * Dijaga di berkas ini, bukan hanya di uji AuditLogger, karena
         * halaman inilah yang mengubah akibatnya: sebelum ia ada, satu
         * alamat yang lolos hanya terbaca oleh orang yang membuka MySQL.
         */
        $entry = $this->logger()->logWithoutActor('complaint.created_anonymous', ['tiket' => 'PGD-1']);

        $tersimpan = DB::table('audit_logs')->where('id', $entry->id)->first();

        self::assertNull($tersimpan->actor_id);
        self::assertNull($tersimpan->ip_address, 'Baris RULE-09 membawa alamat IP — tautan ke pelapor anonim terbuka kembali.');
        self::assertNull($tersimpan->user_agent);

        $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entri.data.0.action', 'complaint.created_anonymous')
                ->where('entri.data.0.ip_address', null));
    }

    #[Test]
    #[TestDox('Alamat IP tindakan publik tetap utuh — ia satu-satunya petunjuk penyalahgunaan')]
    public function alamat_ip_tindakan_publik_tetap_utuh(): void
    {
        /*
         * Sisi sebaliknya, dan ia sama pentingnya. Baris TANPA pelaku yang
         * memang membawa alamat adalah tindakan publik — `letter.tracked`,
         * `letter.tracking_failed`, `citizen.registered`, percobaan masuk
         * yang gagal. Alamatnya adalah satu-satunya petunjuk ketika seseorang
         * mencoba menebak kode pelacakan ribuan kali.
         *
         * Penyamaran yang dipasang "untuk setiap baris tanpa pelaku" akan
         * membutakan tepat baris-baris ini demi perlindungan yang sudah
         * diperoleh di tempat lain. Uji ini menahan perubahan itu.
         */
        $entry = $this->logger()->log('letter.tracking_failed');

        DB::table('audit_logs')->where('id', $entry->id)->update(['ip_address' => '203.0.113.47']);

        $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas')
            ->assertOk()
            ->assertSee('203.0.113.47');
    }

    #[Test]
    #[TestDox('Alamat IP juga utuh di berkas ekspor')]
    public function alamat_ip_utuh_di_ekspor(): void
    {
        $entry = $this->logger()->log('resident.updated', actor: $this->admin);

        DB::table('audit_logs')->where('id', $entry->id)->update(['ip_address' => '198.51.100.9']);

        self::assertStringContainsString('198.51.100.9', $this->csvContent());
    }

    /* ============================== Saringan ============================== */

    #[Test]
    #[TestDox('Saringan aksi hanya memuat baris dengan aksi itu')]
    public function saringan_aksi_bekerja(): void
    {
        $this->logger()->log('resident.created', actor: $this->admin);
        $this->logger()->log('letter.issued', actor: $this->admin);

        $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas?action=letter.issued')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.action', 'letter.issued')
                ->has('entri.data', 1)
                ->where('entri.data.0.action', 'letter.issued'));
    }

    #[Test]
    #[TestDox('Saringan tanggal memakai batas akhir hari, bukan tengah hari')]
    public function saringan_tanggal_memuat_seluruh_hari_terakhir(): void
    {
        /*
         * Jebakan yang dijaga uji ini: batas atas yang ditulis sebagai
         * "sampai <tanggal> 00:00" akan membuang SELURUH baris pada hari
         * terakhir yang diminta orang — termasuk baris pada 23.59, yaitu
         * tepat yang dicari pemeriksa yang menelusuri satu hari kerja.
         */
        $entry = $this->logger()->log('resident.updated', actor: $this->admin);

        DB::table('audit_logs')
            ->where('id', $entry->id)
            ->update(['created_at' => '2026-06-15 23:59:59.998']);

        $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas?from=2026-06-15&to=2026-06-15')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('entri.data', 1));

        $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas?from=2026-06-16&to=2026-06-16')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('entri.data', 0));
    }

    #[Test]
    #[TestDox('Tanggal yang bukan tanggal ditolak validasi, bukan menjadi galat 500')]
    public function tanggal_tidak_sah_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas?from=kemarin')
            ->assertSessionHasErrors('from');
    }

    /* ======================== Kursor & COUNT(*) ========================== */

    #[Test]
    #[TestDox('Pembukaan halaman tidak menjalankan satu pun COUNT(*)')]
    public function halaman_tidak_menghitung_seluruh_tabel(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->logger()->log('resident.updated', actor: $this->admin);
        }

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($this->admin)->get('/pengaturan/log-aktivitas')->assertOk();

        /*
         * Diperiksa dari kueri yang BENAR-BENAR dijalankan, bukan dengan
         * membaca kodenya: `paginate()` mudah sekali masuk kembali ke
         * halaman ini lewat satu perubahan yang tampak tidak berbahaya, dan
         * biayanya — 95 ms atas 1,5 juta baris, pada setiap pembukaan —
         * tidak akan terlihat di basis data pengembangan yang kecil.
         */
        $counts = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains(strtolower($sql), 'count(*)')
                && str_contains($sql, 'audit_logs'),
        ));

        self::assertSame([], $counts, 'Halaman Log Aktivitas menjalankan COUNT(*) atas audit_logs.');
    }

    #[Test]
    #[TestDox('Kursor tidak melompati baris yang waktunya sama persis')]
    public function kursor_tidak_melompati_baris_berwaktu_sama(): void
    {
        /*
         * `created_at` berpresisi milidetik, dan impor massal menulis
         * puluhan baris dalam satu milidetik yang sama. Kursor yang
         * mengurutkan menurut `created_at` SAJA tidak punya pemutus seri,
         * sehingga baris-baris itu dapat terlewat atau muncul dua kali —
         * dan pada audit log, baris yang hilang dari layar adalah bukti
         * yang hilang dari pandangan pemeriksa.
         *
         * Diperiksa lewat HTTP dengan `next_url` yang halaman itu sendiri
         * kirimkan, bukan dengan menyusun kursor di dalam uji: yang harus
         * terbukti benar adalah jalur yang dipakai tombol "Berikutnya".
         */
        $jumlah = AuditLogReader::PER_PAGE + 2;

        for ($i = 0; $i < $jumlah; $i++) {
            $this->logger()->log('resident.updated', actor: $this->admin);
        }

        /*
         * SELURUH baris diberi `created_at` yang sama persis — bukan hanya
         * dua baris di perbatasan halaman. Dengan satu nilai waktu untuk
         * semuanya, tidak ada satu pun pembanding yang dapat memisahkan
         * baris selain `id`, sehingga kursor tanpa pemutus seri tidak punya
         * kemungkinan lolos karena kebetulan.
         */
        DB::table('audit_logs')
            ->where('action', 'resident.updated')
            ->update(['created_at' => '2026-06-20 08:00:00.000']);

        $terlihat = [];
        $url = '/pengaturan/log-aktivitas?action=resident.updated';
        $halaman = 0;

        while ($url !== null && $halaman < 10) {
            $response = $this->actingAs($this->admin)->get($url);
            $response->assertOk();

            /** @var array{props: array{entri: array{data: list<array{id: int}>, next_url: string|null}}} $payload */
            $payload = $response->viewData('page');

            foreach ($payload['props']['entri']['data'] as $row) {
                $terlihat[] = $row['id'];
            }

            $url = $payload['props']['entri']['next_url'];
            $halaman++;
        }

        self::assertCount($jumlah, $terlihat, 'Jumlah baris yang terlihat di seluruh halaman tidak utuh.');
        self::assertCount($jumlah, array_unique($terlihat), 'Ada baris yang muncul di dua halaman.');
        self::assertGreaterThan(1, $halaman, 'Uji ini hanya bermakna bila halamannya lebih dari satu.');
    }

    /* =============================== Ekspor ============================== */

    #[Test]
    #[TestDox('Ekspor audit log ikut tercatat di audit log, beserta jumlah barisnya')]
    public function ekspor_tercatat_beserta_jumlah_baris(): void
    {
        $this->logger()->log('resident.created', actor: $this->admin);
        $this->logger()->log('resident.updated', actor: $this->admin);

        $sebelum = AuditLog::query()->count();

        $this->csvContent(['action' => 'resident.updated']);

        $catatan = AuditLog::query()
            ->where('action', 'data.exported')
            ->orderByDesc('id')
            ->firstOrFail();

        self::assertSame($sebelum + 1, AuditLog::query()->count());
        self::assertSame('audit_logs', $catatan->context['entity_type'] ?? null);
        self::assertSame(1, $catatan->context['rows'] ?? null);
        self::assertSame('resident.updated', $catatan->context['filters']['action'] ?? null);
    }

    #[Test]
    #[TestDox('Berkas ekspor diawali BOM UTF-8 agar Excel membacanya benar')]
    public function ekspor_berawalan_bom(): void
    {
        $this->logger()->log('resident.updated', actor: $this->admin);

        self::assertStringStartsWith("\xEF\xBB\xBF", $this->csvContent());
    }

    #[Test]
    #[TestDox('Ekspor menghormati saringan yang sedang aktif')]
    public function ekspor_mengikuti_saringan(): void
    {
        $this->logger()->log('resident.created', actor: $this->admin);
        $this->logger()->log('letter.issued', actor: $this->admin);

        $csv = $this->csvContent(['action' => 'letter.issued']);

        self::assertStringContainsString('letter.issued', $csv);
        self::assertStringNotContainsString('resident.created', $csv);
    }

    /* =============================== Pembantu ============================ */

    private function logger(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    /** @param  array<string, string>  $filters */
    private function csvContent(array $filters = []): string
    {
        $query = $filters === [] ? '' : '?'.http_build_query($filters);

        $response = $this->actingAs($this->admin)
            ->get('/pengaturan/log-aktivitas/ekspor'.$query);

        $response->assertOk();

        return $response->streamedContent();
    }
}
