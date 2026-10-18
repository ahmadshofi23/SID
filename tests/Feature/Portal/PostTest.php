<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Role;
use App\Support\HtmlSanitizer;
use App\Support\VillageTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Konten portal: berita, pengumuman, agenda — FR-PRT-02, FR-PRT-03, UC-11.
 *
 * Tiga hal yang dijaga kelas ini, di luar "CRUD-nya jalan":
 *   1. Penjadwalan terbit benar-benar menyembunyikan tulisan, termasuk dari
 *      peta situs — pengumuman yang dijadwalkan untuk besok tidak boleh bocor.
 *   2. Pemisahan kewenangan menulis dan menerbitkan (matriks RBAC docs/03 §4.9).
 *   3. Isi HTML dibersihkan SAAT DISIMPAN, termasuk skema URL pada tautan.
 */
final class PostTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    /* ========================== Penjadwalan terbit ======================== */

    #[Test]
    #[TestDox('Tulisan terjadwal belum terlihat publik, lalu muncul sendiri saat waktunya tiba')]
    public function tulisan_terjadwal_belum_terlihat(): void
    {
        $post = $this->makePost([
            'title' => 'Hasil Musyawarah Desa',
            'status' => Post::STATUS_TERBIT,
            'published_at' => now()->addDay(),
        ]);

        $this->get('/berita')->assertOk()->assertDontSee('Hasil Musyawarah Desa', false);
        $this->get('/berita/'.$post->slug)->assertNotFound();

        /*
         * Tidak ada job yang dijalankan di sini dengan sengaja: pagarnya
         * adalah perbandingan waktu di dalam kueri. Tulisan muncul karena
         * waktunya lewat, bukan karena sesuatu berhasil berjalan.
         */
        $this->travelTo(now()->addDays(2));

        $this->get('/berita')->assertOk()->assertSee('Hasil Musyawarah Desa', false);
        $this->get('/berita/'.$post->slug)->assertOk();
    }

    #[Test]
    #[TestDox('Draf dan arsip tidak dapat dibuka publik')]
    public function draf_dan_arsip_tidak_publik(): void
    {
        $draf = $this->makePost(['status' => Post::STATUS_DRAFT, 'published_at' => null]);
        $arsip = $this->makePost(['status' => Post::STATUS_ARSIP, 'published_at' => now()->subMonth()]);

        $this->get('/berita/'.$draf->slug)->assertNotFound();
        $this->get('/berita/'.$arsip->slug)->assertNotFound();
    }

    #[Test]
    #[TestDox('Peta situs memakai saringan terbit yang sama — tulisan terjadwal tidak diundang perayap')]
    public function sitemap_tidak_memuat_tulisan_terjadwal(): void
    {
        $terbit = $this->makePost(['status' => Post::STATUS_TERBIT, 'published_at' => now()->subHour()]);
        $terjadwal = $this->makePost([
            'title' => 'Pengumuman Besok',
            'status' => Post::STATUS_TERBIT,
            'published_at' => now()->addWeek(),
        ]);

        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString($terbit->slug, $xml);
        $this->assertStringNotContainsString($terjadwal->slug, $xml,
            'Peta situs mengundang perayap ke tulisan yang belum waktunya terbit.');
    }

    /* ======================== Satu tulisan, satu alamat =================== */

    #[Test]
    #[TestDox('Pengumuman tidak dapat dibuka lewat /berita/{slug}')]
    public function jenis_terikat_pada_ruas_url(): void
    {
        $post = $this->makePost([
            'type' => Post::TYPE_PENGUMUMAN,
            'status' => Post::STATUS_TERBIT,
            'published_at' => now()->subHour(),
        ]);

        $this->get('/pengumuman/'.$post->slug)->assertOk();

        /*
         * 404, bukan halaman yang sama dengan remah roti keliru: satu tulisan
         * harus punya satu alamat, kalau tidak mesin pencari mengindeksnya
         * berkali-kali dan tautan yang dibagikan warga tidak konsisten.
         */
        $this->get('/berita/'.$post->slug)->assertNotFound();
        $this->get('/agenda/'.$post->slug)->assertNotFound();
    }

    /* ======================= Pemisahan kewenangan ========================= */

    #[Test]
    #[TestDox('Operator dapat menulis draf tetapi tidak menerbitkan (matriks RBAC: OP = C,R,U)')]
    public function operator_tidak_dapat_menerbitkan(): void
    {
        $operator = $this->userWithRole(Role::OPERATOR);

        $this->actingAs($operator)
            ->post('/portal/konten', $this->payload(['status' => Post::STATUS_DRAFT]))
            ->assertRedirect();

        $this->assertSame(1, Post::query()->where('status', Post::STATUS_DRAFT)->count());

        $response = $this->actingAs($operator)
            ->post('/portal/konten', $this->payload([
                'title' => 'Berita Kedua',
                'status' => Post::STATUS_TERBIT,
            ]));

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, Post::query()->where('status', Post::STATUS_TERBIT)->count(),
            'Operator berhasil menerbitkan konten ke situs resmi desa.');
    }

    #[Test]
    #[TestDox('Operator tidak dapat menarik kembali tulisan yang sudah terbit')]
    public function operator_tidak_dapat_menarik_tulisan_terbit(): void
    {
        $post = $this->makePost(['status' => Post::STATUS_TERBIT, 'published_at' => now()->subDay()]);

        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post('/portal/konten/'.$post->id, $this->payload([
                'title' => $post->title,
                'status' => Post::STATUS_ARSIP,
            ]))
            ->assertSessionHasErrors('status');

        $this->assertSame(Post::STATUS_TERBIT, $post->fresh()?->status,
            'Pemisahan kewenangan hanya berlaku satu arah — siapa pun yang dapat '
            .'menyunting dapat menghilangkan pengumuman resmi.');
    }

    #[Test]
    #[TestDox('Admin Desa dapat menerbitkan')]
    public function admin_dapat_menerbitkan(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post('/portal/konten', $this->payload(['status' => Post::STATUS_TERBIT]))
            ->assertRedirect();

        $post = Post::query()->firstOrFail();

        $this->assertSame(Post::STATUS_TERBIT, $post->status);
        $this->assertNotNull($post->published_at,
            'Tulisan terbit tanpa tanggal tidak akan pernah lolos saringan publik.');
    }

    #[Test]
    #[TestDox('Kepala Desa melihat daftar tetapi tidak membuka penyuntingan (matriks RBAC: KD = R,A)')]
    public function kepala_desa_hanya_melihat_dan_menerbitkan(): void
    {
        $kades = $this->userWithRole(Role::KEPALA_DESA);

        $this->actingAs($kades)->get('/portal/konten')->assertOk();
        $this->actingAs($kades)->get('/portal/konten/tambah')->assertForbidden();
    }

    #[Test]
    #[TestDox('Peran tanpa izin portal sama sekali tidak melihat daftar')]
    public function peran_tanpa_izin_portal_ditolak(): void
    {
        $this->actingAs($this->userWithRole(Role::KEPALA_DUSUN))
            ->get('/portal/konten')
            ->assertForbidden();
    }

    /* ============================== Sanitasi ============================== */

    #[Test]
    #[TestDox('Skrip dan tautan javascript: dibuang saat disimpan, bukan saat ditampilkan')]
    public function isi_dibersihkan_saat_disimpan(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post('/portal/konten', $this->payload([
                'status' => Post::STATUS_TERBIT,
                'body' => '<p>Halo warga</p>'
                    .'<script>alert(1)</script>'
                    .'<p><a href="javascript:alert(2)">klik</a></p>'
                    .'<p><a href="https://desa.id/x">tautan sah</a></p>'
                    .'<img src="data:text/html,<script>alert(3)</script>" alt="x">',
            ]))
            ->assertRedirect();

        $body = (string) Post::query()->firstOrFail()->body;

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('data:text/html', $body);

        // Tautan sah dan teksnya tetap utuh — pembersihan bukan pemangkasan.
        $this->assertStringContainsString('https://desa.id/x', $body);
        $this->assertStringContainsString('Halo warga', $body);
        $this->assertStringContainsString('klik', $body,
            'Teks di dalam tautan yang ditolak ikut hilang; kalimat warga lenyap '
            .'tanpa penjelasan.');
    }

    #[Test]
    #[TestDox('Profil artikel mengizinkan tautan & gambar; profil surat tetap tidak')]
    public function profil_sanitasi_terpisah(): void
    {
        $sanitizer = app(HtmlSanitizer::class);
        $html = '<p><a href="https://desa.id">tautan</a><img src="/foto.jpg" alt="foto"></p>';

        $artikel = $sanitizer->clean($html, HtmlSanitizer::PROFIL_ARTIKEL);
        $surat = $sanitizer->clean($html);

        $this->assertStringContainsString('<a href="https://desa.id"', $artikel);
        $this->assertStringContainsString('<img src="/foto.jpg"', $artikel);

        $this->assertStringNotContainsString('<a ', $surat,
            'Template surat resmi tidak pernah memuat tautan; mengizinkannya '
            .'memperlebar permukaan serangan pada dokumen yang dibaca Kepala Desa.');
        $this->assertStringNotContainsString('<img', $surat);
    }

    /* ========================== Waktu kalender desa ======================= */

    #[Test]
    #[TestDox('KEP-010: jam agenda yang diketik petugas dibaca pada kalender desa, bukan UTC')]
    public function waktu_agenda_dibaca_pada_kalender_desa(): void
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post('/portal/konten', $this->payload([
                'type' => Post::TYPE_AGENDA,
                'title' => 'Musyawarah Dusun',
                'status' => Post::STATUS_TERBIT,
                'event_start_at' => '2026-11-10T08:00',
                'event_location' => 'Balai Desa',
            ]))
            ->assertRedirect();

        $post = Post::query()->firstOrFail();

        $this->assertSame(
            '2026-11-10 08:00',
            VillageTime::local($post->event_start_at)->format('Y-m-d H:i'),
            'Jam kegiatan bergeser — warga akan datang pada jam yang salah.'
        );

        // Tersimpan UTC di basis data (KEP-010).
        $this->assertSame('2026-11-10 01:00', $post->event_start_at->utc()->format('Y-m-d H:i'));
    }

    /* ================================ Slug ================================ */

    #[Test]
    #[TestDox('Slug tidak berubah setelah tulisan terbit, agar tautan yang sudah dibagikan tetap hidup')]
    public function slug_beku_setelah_terbit(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->post('/portal/konten', $this->payload([
            'title' => 'Jadwal Posyandu Oktober',
            'status' => Post::STATUS_TERBIT,
        ]))->assertRedirect();

        $post = Post::query()->firstOrFail();
        $slugAwal = $post->slug;

        $this->actingAs($admin)->post('/portal/konten/'.$post->id, $this->payload([
            'title' => 'Jadwal Posyandu Oktober (Ralat)',
            'status' => Post::STATUS_TERBIT,
        ]))->assertRedirect();

        $this->assertSame($slugAwal, $post->fresh()?->slug);
    }

    #[Test]
    #[TestDox('Slug masih mengikuti judul selama tulisan belum pernah terbit')]
    public function slug_mengikuti_judul_selama_draf(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->post('/portal/konten', $this->payload([
            'title' => 'Judul Semetara',
            'status' => Post::STATUS_DRAFT,
        ]))->assertRedirect();

        $post = Post::query()->firstOrFail();

        $this->actingAs($admin)->post('/portal/konten/'.$post->id, $this->payload([
            'title' => 'Judul Sementara Yang Benar',
            'status' => Post::STATUS_DRAFT,
        ]))->assertRedirect();

        $this->assertSame('judul-sementara-yang-benar', $post->fresh()?->slug);
    }

    #[Test]
    #[TestDox('Dua tulisan berjudul sama mendapat slug berbeda')]
    public function slug_unik(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        foreach ([1, 2] as $_) {
            $this->actingAs($admin)
                ->post('/portal/konten', $this->payload([
                    'title' => 'Kerja Bakti',
                    'status' => Post::STATUS_TERBIT,
                ]))
                ->assertRedirect();
        }

        $this->assertSame(
            ['kerja-bakti', 'kerja-bakti-2'],
            Post::query()->orderBy('created_at')->pluck('slug')->all()
        );
    }

    /* =============================== Sampul =============================== */

    #[Test]
    #[TestDox('Berkas yang bukan gambar ditolak berdasarkan ISINYA, bukan namanya')]
    public function sampul_diperiksa_dari_isi_berkas(): void
    {
        $palsu = UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo "halo";');

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post('/portal/konten', [
                ...$this->payload(['status' => Post::STATUS_DRAFT]),
                'cover' => $palsu,
            ])
            ->assertSessionHasErrors('cover');

        $this->assertSame(0, Post::query()->count(),
            'Tulisan tersimpan walau sampulnya ditolak — pemeriksaan harus '
            .'terjadi sebelum barisnya dibuat.');
    }

    /* ============================ Audit & hapus =========================== */

    #[Test]
    #[TestDox('Penghapusan menuntut alasan dan tercatat di audit log')]
    public function penghapusan_tercatat(): void
    {
        $post = $this->makePost(['status' => Post::STATUS_TERBIT, 'published_at' => now()->subDay()]);
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->delete('/portal/konten/'.$post->id)
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->delete('/portal/konten/'.$post->id, ['reason' => 'Pengumuman dibatalkan rapat desa'])
            ->assertRedirect('/portal/konten');

        $this->assertSoftDeleted('posts', ['id' => $post->id]);

        $entry = AuditLog::query()->where('action', 'post.deleted')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('dibatalkan rapat desa', json_encode($entry->context) ?: '');
    }

    /* ============================== Beranda =============================== */

    #[Test]
    #[TestDox('Beranda menampilkan tulisan terbit, dan tidak menampilkan blok kosong bila belum ada')]
    public function beranda_menampilkan_kabar_desa(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Kabar Desa', false);

        $this->makePost([
            'title' => 'Perbaikan Jalan Dusun Krajan',
            'status' => Post::STATUS_TERBIT,
            'published_at' => now()->subHour(),
        ]);

        $this->get('/')->assertOk()
            ->assertSee('Kabar Desa', false)
            ->assertSee('Perbaikan Jalan Dusun Krajan', false);
    }

    /* ================================ Bantu =============================== */

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return [
            'type' => Post::TYPE_BERITA,
            'title' => 'Berita Uji',
            'excerpt' => 'Ringkasan singkat.',
            'body' => '<p>Isi berita.</p>',
            'status' => Post::STATUS_DRAFT,
            ...$override,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function makePost(array $attributes = []): Post
    {
        $judul = (string) ($attributes['title'] ?? 'Berita Uji '.uniqid());

        $post = new Post;
        $post->forceFill([
            'type' => Post::TYPE_BERITA,
            'slug' => Str::slug($judul).'-'.uniqid(),
            'title' => $judul,
            'body' => '<p>Isi.</p>',
            'status' => Post::STATUS_DRAFT,
            'published_at' => null,
            ...$attributes,
        ])->save();

        return $post;
    }
}
