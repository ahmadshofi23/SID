<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Auth\AccessScope;
use App\Models\Family;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Services\Letter\LetterRequestService;
use App\Support\NameNormalizer;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Notifikasi perubahan status — FR-SRT-14, BR-11, UC-05.
 *
 * Yang dijaga di sini bukan "notifikasinya terkirim", melainkan SIAPA yang
 * menerimanya. Pertanyaan itu tidak sesederhana kelihatannya: pengajuan
 * dibuat ayah untuk anaknya, atau dibuat petugas loket atas nama warga yang
 * belum punya akun sama sekali.
 */
final class LetterNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rt;

    private Family $family;

    private Resident $ayah;

    private Resident $anak;

    private User $akunAyah;

    private LetterType $domisili;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000007001');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Notifikasi 3',
        ])->save();

        $this->ayah = $this->makeResident('3201011007400021', 'Bapak Notif');
        $this->anak = $this->makeResident('3201011007400022', 'Anak Notif', 'anak');

        $this->akunAyah = $this->userWithRole(Role::WARGA, residentId: $this->ayah->id);
        $this->domisili = LetterType::query()->where('code', 'SKD')->firstOrFail();
    }

    /* ============================== Penerima ============================= */

    #[Test]
    #[TestDox('Pemohon diberi tahu saat pengajuannya berpindah status')]
    public function pemohon_diberi_tahu(): void
    {
        $request = $this->ajukan($this->ayah);

        $this->assertSame(1, $this->notifikasiMilik($this->akunAyah)->count());

        $this->akunAyah->notifications()->delete();

        $this->tolak($request, 'Berkas tidak lengkap');

        $notif = $this->notifikasiMilik($this->akunAyah)->first();

        $this->assertNotNull($notif);
        $this->assertSame('surat.status', $notif->data['kategori']);
        $this->assertStringContainsString('ditolak', mb_strtolower((string) $notif->data['judul']));
        $this->assertStringContainsString('Berkas tidak lengkap', (string) $notif->data['isi'],
            'Alasan penolakan tidak ikut dikabarkan — warga harus menelepon kantor '
            .'desa untuk menanyakan apa yang salah (BR-11).');
    }

    #[Test]
    #[TestDox('Ayah yang mengurus surat anaknya tetap yang diberi tahu, walau anaknya belum punya akun')]
    public function pengaju_diberi_tahu_walau_pemohonnya_orang_lain(): void
    {
        $this->ajukan($this->anak, actor: $this->akunAyah);

        $this->assertSame(1, $this->notifikasiMilik($this->akunAyah)->count(),
            'Yang menunggu kabar adalah yang mengurus suratnya.');
    }

    #[Test]
    #[TestDox('Pengajuan loket atas nama warga tanpa akun tidak menggagalkan apa pun')]
    public function warga_tanpa_akun_tidak_menggagalkan(): void
    {
        $lepas = $this->makeResident('3201011007400023', 'Tanpa Akun', 'anak');

        /*
         * Keadaan normal, bukan kegagalan: sebagian besar pengajuan loket
         * dibuat atas nama warga yang belum mendaftar. Jalur mereka tetap
         * halaman pelacakan publik dengan nomor tiket (KEP-014 §5).
         */
        $request = $this->ajukan($lepas, channel: 'walk_in',
            actor: $this->userWithRole(Role::OPERATOR));

        $this->assertNotNull($request->ticket_number);
        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    #[Test]
    #[TestDox('Petugas pemeriksa diberi tahu pengajuan DARING, tetapi tidak pengajuan loket')]
    public function petugas_hanya_diberi_tahu_pengajuan_daring(): void
    {
        $kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN);

        $this->ajukan($this->ayah, channel: 'walk_in',
            actor: $this->userWithRole(Role::OPERATOR));

        $this->assertSame(0, $this->notifikasiMilik($kasi)->count(),
            'Petugas diberi tahu tentang pekerjaan yang baru saja ia buat sendiri — '
            .'itu melatih orang mengabaikan lonceng.');

        $this->anak->refresh();
        $this->ajukan($this->anak, channel: 'online', actor: $this->akunAyah);

        $notif = $this->notifikasiMilik($kasi)->first();

        $this->assertNotNull($notif, 'Pengajuan daring tidak memberi tahu pemeriksanya.');
        $this->assertSame('surat.antrean', $notif->data['kategori']);
    }

    #[Test]
    #[TestDox('Pembuatan draf tidak memberi tahu siapa pun')]
    public function draf_tidak_memberi_tahu(): void
    {
        $actor = $this->akunAyah->fresh(['roles.permissions', 'resident']);

        $this->actingAs($actor)->app->make(LetterRequestService::class)->create(
            scope: AccessScope::forUser($actor),
            type: $this->domisili,
            residentId: $this->ayah->id,
            purpose: 'Uji draf',
            formInput: ['keperluan' => 'Uji draf'],
            channel: 'online',
            submit: false,
        );

        $this->assertSame(0, DatabaseNotification::query()->count(),
            'Draf belum berarti apa-apa bagi warga.');
    }

    #[Test]
    #[TestDox('RULE-05: pembatalan surat terbit dikabarkan terpisah, karena status pengajuan tidak berubah')]
    public function pembatalan_surat_dikabarkan(): void
    {
        $request = $this->ajukan($this->ayah);
        $letter = $this->terbitkan($request);

        $this->akunAyah->notifications()->delete();

        app(LetterRequestService::class)->cancelLetter(
            $letter, 'Nomor surat keliru', $this->userWithRole(Role::ADMIN_DESA)->id
        );

        $notif = $this->notifikasiMilik($this->akunAyah)->first();

        $this->assertNotNull($notif);
        $this->assertSame('surat.dibatalkan', $notif->data['kategori']);
        $this->assertStringContainsString('tidak lagi berlaku', (string) $notif->data['isi'],
            'Warga tidak diberi tahu bahwa surat yang sudah ia pegang kehilangan keabsahannya.');
    }

    /* ================================ Layar ============================== */

    #[Test]
    #[TestDox('Halaman notifikasi hanya memuat notifikasi milik sendiri')]
    public function halaman_hanya_memuat_milik_sendiri(): void
    {
        $this->ajukan($this->ayah);

        $orangLain = $this->userWithRole(Role::OPERATOR);

        $this->actingAs($this->akunAyah)->get('/notifikasi')
            ->assertOk()
            ->assertSee('Surat Keterangan Domisili', false);

        $body = (string) $this->actingAs($orangLain)->get('/notifikasi')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('sudah masuk antrean', $body,
            'Notifikasi milik warga lain bocor ke layar petugas.');
    }

    #[Test]
    #[TestDox('Notifikasi milik orang lain menghasilkan 404, bukan 403')]
    public function notifikasi_orang_lain_404(): void
    {
        $this->ajukan($this->ayah);

        $notif = $this->notifikasiMilik($this->akunAyah)->firstOrFail();

        $this->actingAs($this->userWithRole(Role::OPERATOR))
            ->post('/notifikasi/'.$notif->id.'/baca')
            ->assertNotFound();

        $this->assertNull($notif->fresh()?->read_at);
    }

    #[Test]
    #[TestDox('Membuka notifikasi menandainya terbaca dan mengantar ke halamannya')]
    public function membuka_menandai_terbaca(): void
    {
        $request = $this->ajukan($this->ayah);
        $notif = $this->notifikasiMilik($this->akunAyah)->firstOrFail();

        $this->actingAs($this->akunAyah)
            ->post('/notifikasi/'.$notif->id.'/baca')
            ->assertRedirect('/warga/pengajuan/'.$request->id);

        $this->assertNotNull($notif->fresh()?->read_at);
    }

    #[Test]
    #[TestDox('Jumlah belum dibaca dikirim ke setiap layar sebagai prop bersama')]
    public function jumlah_belum_dibaca_dibagikan(): void
    {
        $this->ajukan($this->ayah);

        /*
         * Diperiksa lewat muatan Inertia, bukan dengan mencocokkan potongan
         * HTML: bentuk escaping atribut `data-page` adalah urusan kerangka
         * kerja dan dapat berubah antar versi, sementara yang hendak dijaga
         * di sini adalah NILAI prop-nya.
         *
         * Nilainya ikut diperiksa, bukan hanya keberadaan kuncinya: closure
         * yang keliru mengembalikan 0 untuk semua orang akan lolos
         * pemeriksaan keberadaan, dan lonceng diam selamanya tanpa ada yang
         * curiga.
         */
        $this->actingAs($this->akunAyah)
            ->get('/warga')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notifikasi_belum_dibaca', 1)
            );
    }

    #[Test]
    #[TestDox('Tandai semua dibaca mengosongkan penanda')]
    public function tandai_semua_dibaca(): void
    {
        $this->ajukan($this->ayah);
        $this->ajukan($this->anak, actor: $this->akunAyah);

        $this->assertSame(2, $this->akunAyah->unreadNotifications()->count());

        $this->actingAs($this->akunAyah)
            ->post('/notifikasi/baca-semua')
            ->assertRedirect();

        $this->assertSame(0, $this->akunAyah->fresh()?->unreadNotifications()->count());
    }

    /* ================================ Bantu =============================== */

    /** @return Builder<DatabaseNotification> */
    private function notifikasiMilik(User $user)
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->orderByDesc('created_at');
    }

    private function ajukan(
        Resident $resident,
        string $channel = 'online',
        ?User $actor = null,
    ): LetterRequest {
        $actor ??= $this->akunAyah;
        $actor = $actor->fresh(['roles.permissions', 'resident']);

        return $this->actingAs($actor)->app->make(LetterRequestService::class)->create(
            scope: AccessScope::forUser($actor),
            type: $this->domisili,
            residentId: $resident->id,
            purpose: 'Keperluan uji',
            formInput: ['keperluan' => 'Keperluan uji'],
            channel: $channel,
        );
    }

    private function tolak(LetterRequest $request, string $reason): void
    {
        $kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN)->fresh(['roles.permissions']);

        $this->actingAs($kasi)->app->make(LetterRequestService::class)
            ->reject(AccessScope::forUser($kasi), $request, $reason);
    }

    private function terbitkan(LetterRequest $request): Letter
    {
        $kasi = $this->userWithRole(Role::KASI_PEMERINTAHAN)->fresh(['roles.permissions']);
        $service = $this->actingAs($kasi)->app->make(LetterRequestService::class);
        $service->verify(AccessScope::forUser($kasi), $request->refresh());

        $kades = $this->userWithRole(Role::KEPALA_DESA)->fresh(['roles.permissions']);

        return $this->actingAs($kades)->app->make(LetterRequestService::class)
            ->issue(AccessScope::forUser($kades), $request->refresh());
    }

    private function makeResident(string $nik, string $name, string $relation = 'kepala_keluarga'): Resident
    {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $this->family->id,
            'region_id' => $this->rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_date' => '1985-06-15',
            'gender' => 'L',
            'family_relation_code' => $relation,
            'religion_code' => 'islam',
            'citizenship' => 'WNI',
            'marital_status_code' => 'kawin',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }
}
