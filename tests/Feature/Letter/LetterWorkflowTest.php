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
use App\Services\Letter\LetterRequestService;
use App\Support\NameNormalizer;
use App\Support\VillageTime;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Alur persuratan — FR-SRT-03 s.d. FR-SRT-14, RULE-04 s.d. RULE-06.
 */
final class LetterWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private Region $rt;

    private Family $family;

    private LetterType $sktm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $this->rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $this->family = new Family;
        $this->family->setKkNumber('3201010000009001');
        $this->family->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Surat 1',
        ])->save();

        $this->sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();
    }

    /* ===================== Alur lengkap ===================== */

    #[Test]
    #[TestDox('Alur dua tahap: ajukan → verifikasi → terbit, nomor muncul HANYA di langkah terakhir (RULE-04)')]
    public function alur_dua_tahap_sampai_terbit(): void
    {
        $resident = $this->makeResident('3201010101800301', 'Pemohon Sktm');
        $operator = $this->userWithRole(Role::KASI_PEMERINTAHAN);
        $kasi = $this->userWithRole(Role::KASI_KESEJAHTERAAN);
        $kades = $this->userWithRole(Role::KEPALA_DESA);

        // 1. Pengajuan di loket oleh operator
        $this->actingAs($operator)->post('/surat', [
            'letter_type_id' => $this->sktm->id,
            'resident_id' => $resident->id,
            'form' => [
                'keperluan' => 'Pengajuan beasiswa',
                'keterangan_tambahan' => 'Anak bernama Budi, kelas 10 SMAN 1.',
            ],
        ])->assertRedirect();

        $request = LetterRequest::query()->where('resident_id', $resident->id)->firstOrFail();

        $this->assertSame(LetterRequest::STATUS_SUBMITTED, $request->status,
            'Pengajuan loket harus langsung masuk antrean, bukan tersimpan sebagai draf.');
        $this->assertNotNull($request->due_at, 'Batas waktu SLA wajib dihitung saat diajukan.');

        // Nomor surat BELUM ada — inilah RULE-04.
        $this->assertDatabaseCount('letters', 0);
        // Penghitung nomor tidak boleh tersentuh sebelum surat disetujui.
        $this->assertDatabaseCount('letter_counters', 0);

        // 2. Verifikasi oleh Kasi Kesejahteraan (langkah 1 dari alur SKTM)
        $this->actingAs($kasi)
            ->post("/surat/{$request->id}/verifikasi")
            ->assertRedirect();

        $this->assertSame(LetterRequest::STATUS_VERIFIED, $request->fresh()->status);
        $this->assertDatabaseCount('letters', 0);

        // 3. Penerbitan oleh Kepala Desa
        $this->actingAs($kades)
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(LetterRequest::STATUS_ISSUED, $request->status);
        $this->assertNotNull($request->completed_at);

        $letter = Letter::query()->where('request_id', $request->id)->firstOrFail();

        $this->assertSame(1, $letter->sequence_number);
        $this->assertStringContainsString('SKTM', $letter->letter_number);
        $this->assertStringContainsString('1/', $letter->letter_number);

        // Isi surat sudah dirender dan disimpan permanen (ADR-003).
        $this->assertStringContainsString('keluarga yang tidak mampu', $letter->rendered_html);
        $this->assertStringContainsString('PENGAJUAN BEASISWA', $letter->rendered_html,
            'Pemformat {{#upper}} harus diterapkan pada isi surat.');
        $this->assertStringContainsString(
            'Anak bernama Budi', $letter->rendered_html,
            'Isian dinamis harus masuk ke surat.'
        );

        // Snapshot menyimpan data SAAT terbit, tanpa NIK lengkap.
        $this->assertSame('Pemohon Sktm', $letter->snapshot['nama']);
        $this->assertSame($resident->nik_masked, $letter->snapshot['nik'],
            'Snapshot tidak boleh memuat NIK lengkap dalam bentuk polos.');

        /*
         * Masa berlaku SKTM 3 bulan, dihitung dari kalender desa — bukan UTC.
         * Memakai now() di sini membuat uji ini gagal setiap malam antara
         * pukul 00.00 dan 07.00 WIB, ketika tanggal desa sudah berganti
         * sementara tanggal UTC belum.
         */
        $this->assertNotNull($letter->valid_until);
        $this->assertSame(
            VillageTime::now()->addMonths(3)->toDateString(),
            $letter->valid_until->toDateString(),
        );

        // Riwayat memuat setiap transisi.
        $statuses = $request->logs()->orderBy('created_at')->pluck('to_status')->all();

        $this->assertSame([
            LetterRequest::STATUS_DRAFT,
            LetterRequest::STATUS_SUBMITTED,
            LetterRequest::STATUS_VERIFIED,
            LetterRequest::STATUS_ISSUED,
        ], $statuses);
    }

    #[Test]
    #[TestDox('Alur tiga tahap (SKP) perlu dua verifikasi sebelum dapat diterbitkan')]
    public function alur_tiga_tahap_perlu_dua_verifikasi(): void
    {
        $skp = LetterType::query()->where('code', 'SKP')->firstOrFail();
        $resident = $this->makeResident('3201010101800302', 'Pemohon Pindah');

        $request = $this->makeRequest($skp, $resident);
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        /*
         * F3 = verify (Kasi) → review (Sekdes) → approve (Kades).
         *
         * Tiga TAHAP, tetapi hanya DUA pemeriksaan: tahap `approve` adalah
         * penerbitannya sendiri, bukan verifikasi ketiga.
         */
        $this->assertSame(3, $skp->stepCount());
        $this->assertSame(2, $skp->verificationStepCount());

        // Verifikasi pertama: status TETAP diajukan, hanya langkah yang maju.
        $this->actingAs($admin)->post("/surat/{$request->id}/verifikasi")->assertRedirect();
        $request->refresh();

        $this->assertSame(LetterRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame(1, $request->current_step);

        // Penerbitan ditolak — belum diverifikasi penuh.
        $this->actingAs($admin)
            ->post("/surat/{$request->id}/terbitkan")
            ->assertStatus(409);

        // Surat tidak boleh terbit sebelum seluruh tahap pemeriksaan selesai.
        $this->assertDatabaseCount('letters', 0);

        // Verifikasi kedua menyelesaikan pemeriksaan.
        $this->actingAs($admin)->post("/surat/{$request->id}/verifikasi")->assertRedirect();

        $this->assertSame(LetterRequest::STATUS_VERIFIED, $request->fresh()->status);

        $this->actingAs($admin)->post("/surat/{$request->id}/terbitkan")->assertRedirect();

        $this->assertDatabaseCount('letters', 1);
    }

    /* ===================== Mesin status ===================== */

    #[Test]
    #[TestDox('Transisi tidak sah ditolak dengan 409, bukan 422, dan tidak mengubah data (FR-SRT-07)')]
    public function transisi_tidak_sah_ditolak_409(): void
    {
        $resident = $this->makeResident('3201010101800303', 'Pemohon Tolak');
        $request = $this->makeRequest($this->sktm, $resident);
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        // Terbit langsung dari `diajukan` — melangkahi verifikasi.
        $this->actingAs($admin)
            ->post("/surat/{$request->id}/terbitkan")
            ->assertStatus(409);

        $this->assertSame(LetterRequest::STATUS_SUBMITTED, $request->fresh()->status);
        $this->assertDatabaseCount('letters', 0);
    }

    #[Test]
    #[TestDox('Pengajuan yang sudah ditolak tidak dapat dihidupkan kembali')]
    public function status_akhir_tidak_dapat_dihidupkan_kembali(): void
    {
        $resident = $this->makeResident('3201010101800304', 'Pemohon Final');
        $request = $this->makeRequest($this->sktm, $resident);
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->post("/surat/{$request->id}/tolak", [
            'reason' => 'Berkas pengantar RT tidak ada.',
        ])->assertRedirect();

        $this->assertSame(LetterRequest::STATUS_REJECTED, $request->fresh()->status);

        foreach (['verifikasi', 'terbitkan'] as $action) {
            $this->actingAs($admin)
                ->post("/surat/{$request->id}/{$action}")
                ->assertStatus(409);
        }

        $this->assertSame(LetterRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    #[Test]
    #[TestDox('Revisi mengembalikan pengajuan ke pemohon, lalu dapat diajukan ulang')]
    public function revisi_lalu_ajukan_ulang(): void
    {
        $resident = $this->makeResident('3201010101800305', 'Pemohon Revisi');
        $request = $this->makeRequest($this->sktm, $resident);
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        // Catatan revisi WAJIB — tanpa itu pemohon tidak tahu apa yang salah.
        $this->actingAs($admin)
            ->post("/surat/{$request->id}/revisi", ['note' => ''])
            ->assertSessionHasErrors('note');

        $this->actingAs($admin)->post("/surat/{$request->id}/revisi", [
            'note' => 'Surat pengantar RT belum ditandatangani.',
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame(LetterRequest::STATUS_NEEDS_REVISION, $request->status);
        $this->assertSame('Surat pengantar RT belum ditandatangani.', $request->revision_note);
        $this->assertSame(0, $request->current_step,
            'Langkah pemeriksaan harus kembali ke awal setelah revisi.');

        $this->actingAs($admin)->put("/surat/{$request->id}", [
            'form' => ['keperluan' => 'Keringanan biaya sekolah'],
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame(LetterRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame('Keringanan biaya sekolah', $request->form_data['keperluan']);
        $this->assertNull($request->revision_note,
            'Catatan revisi lama harus dibersihkan setelah diajukan ulang.');
    }

    #[Test]
    #[TestDox('Pengajuan yang sedang diperiksa tidak dapat disunting di bawah tangan pemeriksanya')]
    public function pengajuan_dalam_pemeriksaan_tidak_dapat_disunting(): void
    {
        $resident = $this->makeResident('3201010101800306', 'Pemohon Sunting');
        $request = $this->makeRequest($this->sktm, $resident);
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->put("/surat/{$request->id}", [
            'form' => ['keperluan' => 'Pengajuan bantuan sosial'],
        ])->assertStatus(409);

        $this->assertSame(
            'Pengajuan beasiswa',
            $request->fresh()->form_data['keperluan'],
        );
    }

    /* ===================== Aturan bisnis ===================== */

    #[Test]
    #[TestDox('Operator tidak dapat menyetujui pengajuan daring yang dibuatnya sendiri (RULE-06)')]
    public function pemisahan_tugas_ditegakkan(): void
    {
        $resident = $this->makeResident('3201010101800307', 'Pemohon Sendiri');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        /*
         * Kanal `online`, BUKAN walk_in. Pengecualian RULE-06 hanya berlaku
         * untuk layanan loket, tempat operator dan penyetuju memang sering
         * orang yang sama di desa kecil.
         */
        $request = $this->makeRequest($this->sktm, $resident, [
            'requested_by' => $admin->id,
            'channel' => 'online',
            'status' => LetterRequest::STATUS_VERIFIED,
        ]);

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/terbitkan")
            ->assertStatus(403);

        $this->assertDatabaseCount('letters', 0);
    }

    #[Test]
    #[TestDox('Penolakan 403/409 pada permintaan Inertia tampil sebagai pesan, bukan layar yang tidak berubah')]
    public function penolakan_terlihat_oleh_petugas(): void
    {
        $resident = $this->makeResident('3201010101800320', 'Pemohon Pesan');
        $kasiLain = $this->userWithRole(Role::KASI_PEMERINTAHAN);

        // SKTM: tahap pemeriksaan pertama milik Kasi Kesejahteraan.
        $request = $this->makeRequest($this->sktm, $resident);

        /*
         * MENGAPA UJI INI ADA:
         *
         * Uji lain memeriksa KODE STATUS penolakan, dan kode statusnya
         * selalu benar. Namun Inertia menangani 403 atas sebuah POST dengan
         * tidak mengubah apa pun di layar — petugas menekan tombol dan tidak
         * terjadi apa-apa. Cacat ini ditemukan saat menelusuri alur di
         * peramban, bukan oleh pengujian.
         *
         * Header X-Inertia-lah yang membedakan keduanya.
         */
        $this->actingAs($kasiLain)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '1')
            ->post("/surat/{$request->id}/verifikasi")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(LetterRequest::STATUS_SUBMITTED, $request->fresh()->status);

        // Transisi tidak sah (409) juga harus terlihat.
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '1')
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    #[Test]
    #[TestDox('Tombol verifikasi hanya ditawarkan kepada pemeriksa tahap yang berjalan')]
    public function peran_pemeriksa_tahap_dikirim_ke_layar(): void
    {
        $resident = $this->makeResident('3201010101800321', 'Pemohon Tahap');
        $request = $this->makeRequest($this->sktm, $resident);

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->get("/surat/{$request->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('request.step_roles', ['kasi_kesejahteraan'])
                ->where('request.step_role_labels', ['Kasi Kesejahteraan']));
    }

    #[Test]
    #[TestDox('Persetujuan sendiri di loket DIIZINKAN tetapi dicatat ke audit log')]
    public function persetujuan_sendiri_di_loket_dicatat(): void
    {
        $resident = $this->makeResident('3201010101800308', 'Pemohon Loket');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $request = $this->makeRequest($this->sktm, $resident, [
            'requested_by' => $admin->id,
            'channel' => 'walk_in',
            'status' => LetterRequest::STATUS_VERIFIED,
        ]);

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect();

        $this->assertDatabaseCount('letters', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'letter_request.self_approved',
            'entity_id' => $request->id,
        ]);
    }

    #[Test]
    #[TestDox('Penduduk meninggal tidak dapat mengajukan surat (RULE-03)')]
    public function penduduk_tidak_aktif_tidak_dapat_mengajukan(): void
    {
        $resident = $this->makeResident('3201010101800309', 'Sudah Meninggal');
        $resident->forceFill(['status' => 'meninggal'])->save();

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->post('/surat', [
            'letter_type_id' => $this->sktm->id,
            'resident_id' => $resident->id,
            'form' => ['keperluan' => 'Pengajuan beasiswa'],
        ])->assertSessionHasErrors('resident_id');

        $this->assertDatabaseCount('letter_requests', 0);
    }

    #[Test]
    #[TestDox('SKBM ditolak bila status perkawinan penduduk bukan Belum Kawin (docs/13 §5.7)')]
    public function skbm_menolak_status_perkawinan_yang_tidak_sesuai(): void
    {
        $skbm = LetterType::query()->where('code', 'SKBM')->firstOrFail();
        $resident = $this->makeResident('3201010101800310', 'Sudah Kawin');
        $resident->forceFill(['marital_status_code' => 'kawin_tercatat'])->save();

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->post('/surat', [
            'letter_type_id' => $skbm->id,
            'resident_id' => $resident->id,
            'form' => ['keperluan' => 'Melamar kerja'],
        ])->assertSessionHasErrors('resident_id');

        $this->assertDatabaseCount('letter_requests', 0);

        // Dengan status yang benar, pengajuan diterima.
        $resident->forceFill(['marital_status_code' => 'belum_kawin'])->save();

        $this->actingAs($admin)->post('/surat', [
            'letter_type_id' => $skbm->id,
            'resident_id' => $resident->id,
            'form' => ['keperluan' => 'Melamar kerja'],
        ])->assertRedirect();

        $this->assertDatabaseCount('letter_requests', 1);
    }

    #[Test]
    #[TestDox('Pengajuan ganda untuk jenis surat yang sama ditolak selama masih diproses')]
    public function pengajuan_ganda_ditolak(): void
    {
        $resident = $this->makeResident('3201010101800311', 'Pemohon Ganda');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $payload = [
            'letter_type_id' => $this->sktm->id,
            'resident_id' => $resident->id,
            'form' => ['keperluan' => 'Pengajuan beasiswa'],
        ];

        $this->actingAs($admin)->post('/surat', $payload)->assertRedirect();
        $this->actingAs($admin)->post('/surat', $payload)
            ->assertSessionHasErrors('resident_id');

        $this->assertDatabaseCount('letter_requests', 1);
    }

    #[Test]
    #[TestDox('Isian dinamis divalidasi terhadap form_schema; kunci asing dibuang (ADR-002)')]
    public function isian_dinamis_divalidasi_dan_disaring(): void
    {
        $resident = $this->makeResident('3201010101800312', 'Pemohon Isian');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        // Pilihan di luar daftar ditolak — `keperluan` SKTM allow_other, jadi
        // `penghasilan_per_bulan` yang bukan angka yang diuji di sini.
        $this->actingAs($admin)->post('/surat', [
            'letter_type_id' => $this->sktm->id,
            'resident_id' => $resident->id,
            'form' => [
                'keperluan' => 'Pengajuan beasiswa',
                'penghasilan_per_bulan' => 'bukan angka',
            ],
        ])->assertSessionHasErrors('form.penghasilan_per_bulan');

        // Kunci yang tidak ada di skema tidak boleh tersimpan.
        $this->actingAs($admin)->post('/surat', [
            'letter_type_id' => $this->sktm->id,
            'resident_id' => $resident->id,
            'form' => [
                'keperluan' => 'Pengajuan beasiswa',
                'kunci_asing' => '<script>alert(1)</script>',
            ],
        ])->assertRedirect();

        $request = LetterRequest::query()->where('resident_id', $resident->id)->firstOrFail();

        $this->assertArrayNotHasKey('kunci_asing', $request->form_data,
            'Kunci di luar form_schema tersimpan — masukan sembarang akan tercetak di surat.');
    }

    /* ===================== Surat terbit ===================== */

    #[Test]
    #[TestDox('Surat terbit tidak dapat diubah maupun dihapus (ADR-003)')]
    public function surat_terbit_tidak_dapat_diubah(): void
    {
        $letter = $this->issueLetter('3201010101800313', 'Pemohon Kekal');

        try {
            $letter->rendered_html = '<p>diubah</p>';
            $letter->save();
            $this->fail('Surat terbit berhasil diubah — melanggar ADR-003.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak dapat diubah', $e->getMessage());
        }

        /*
         * refresh() WAJIB di sini: save() yang gagal TIDAK mengembalikan
         * atribut ke nilai semula, sehingga `rendered_html` masih berstatus
         * kotor. Tanpa ini, penyimpanan berikutnya — yang seharusnya sah —
         * akan ikut ditolak oleh pagar yang sama.
         */
        $letter->refresh();

        try {
            $letter->delete();
            $this->fail('Surat terbit berhasil dihapus — melanggar ADR-003.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak dapat dihapus', $e->getMessage());
        }

        // Pembatalan dan pencetakan TETAP boleh — keduanya terjadi setelah terbit.
        $letter->status = Letter::STATUS_CANCELLED;
        $letter->cancelled_at = now();
        $letter->cancel_reason = 'Uji';
        $letter->save();

        $this->assertSame(Letter::STATUS_CANCELLED, $letter->fresh()->status);
    }

    #[Test]
    #[TestDox('Badan surat tersimpan utuh: pembuka, blok identitas, isi pokok, penutup')]
    public function badan_surat_tersimpan_utuh(): void
    {
        $letter = $this->issueLetter('3201010101800322', 'Siti Marufah Uji');

        $html = $letter->rendered_html;

        /*
         * Seluruh bagian yang DIBENTUK SISTEM harus ikut tersimpan, bukan
         * dirakit halaman cetak. Bila halaman Vue merakitnya, menyunting
         * halaman itu akan mengubah tampilan surat yang sudah terbit
         * bertahun-tahun lalu (ADR-003).
         */
        $this->assertStringContainsString('Yang bertanda tangan di bawah ini', $html);
        $this->assertStringContainsString('Siti Marufah Uji', $html);
        $this->assertStringContainsString('Demikian surat keterangan ini dibuat', $html);
        $this->assertStringContainsString('keluarga yang tidak mampu', $html);

        // NIK LENGKAP, bukan tersamar: penerima surat mencocokkannya dengan KTP.
        $this->assertStringContainsString('3201010101800322', $html,
            'Blok identitas memuat NIK tersamar — surat tidak dapat dipakai '
            .'untuk mencocokkan identitas dengan KTP.');

        // "Kepala Desa Sukamaju", BUKAN "Kepala Desa Desa Sukamaju".
        $this->assertStringNotContainsString('Desa Desa', $html);

        // Tanggal berbahasa Indonesia, bukan format ISO.
        $this->assertStringNotContainsString('Surat ini berlaku sampai dengan 20', $html);
        $this->assertMatchesRegularExpression(
            '/berlaku sampai dengan \d{1,2} (Januari|Februari|Maret|April|Mei|Juni|'
            .'Juli|Agustus|September|Oktober|November|Desember) \d{4}/',
            $html,
            'Masa berlaku tercetak dalam format mentah, bukan tanggal Indonesia.',
        );

        // Snapshot tetap menyimpan NIK tersamar.
        $this->assertSame($letter->request->resident->nik_masked, $letter->snapshot['nik']);

        // Pengungkapan NIK tercatat (RULE-07).
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'data.pii_revealed',
            'entity_id' => $letter->request->resident_id,
        ]);
    }

    #[Test]
    #[TestDox('Nama wilayah yang sudah memuat kata "Dusun" tidak digandakan di alamat surat')]
    public function awalan_wilayah_tidak_digandakan(): void
    {
        $letter = $this->issueLetter('3201010101800323', 'Pemohon Alamat');

        $this->assertStringNotContainsString('Dusun Dusun', $letter->rendered_html,
            'Alamat tercetak "Dusun Dusun Krajan" — nama wilayah desa sudah '
            .'memuat kata "Dusun".');
    }

    #[Test]
    #[TestDox('Halaman cetak tidak mengirim snapshot ke peramban')]
    public function halaman_cetak_tidak_mengirim_snapshot(): void
    {
        $letter = $this->issueLetter('3201010101800324', 'Pemohon Cetak');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->get("/surat/arsip/{$letter->id}/cetak")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('letter.snapshot'));
    }

    #[Test]
    #[TestDox('Pembatalan surat tidak melepaskan nomornya untuk dipakai ulang (RULE-05)')]
    public function pembatalan_tidak_melepaskan_nomor(): void
    {
        $letter = $this->issueLetter('3201010101800314', 'Pemohon Batal');
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->post("/surat/arsip/{$letter->id}/batalkan", [
            'reason' => 'Nama pemohon salah ketik pada surat.',
        ])->assertRedirect();

        $letter->refresh();

        $this->assertSame(Letter::STATUS_CANCELLED, $letter->status);
        $this->assertSame(1, $letter->sequence_number);

        // Surat berikutnya memakai nomor 2, bukan 1.
        $next = $this->issueLetter('3201010101800315', 'Pemohon Berikut');

        $this->assertSame(2, $next->sequence_number,
            'Nomor surat yang dibatalkan dipakai ulang — dua dokumen berbeda '
            .'akan memiliki nomor yang sama.');
    }

    /* ===================== Verifikasi publik ===================== */

    #[Test]
    #[TestDox('Verifikasi QR dapat diakses tanpa login dan TIDAK membocorkan NIK atau isi surat')]
    public function verifikasi_publik_tidak_membocorkan_data(): void
    {
        $letter = $this->issueLetter('3201010101800316', 'Siti Aminah Rahayu');

        $response = $this->get("/surat/verifikasi/{$letter->verification_code}");

        $response->assertOk();

        /*
         * Diperiksa pada PROPS Inertia, bukan pada HTML mentah.
         *
         * Di dalam HTML, props tertanam sebagai JSON sehingga garis miring
         * nomor surat ter-escape menjadi `1\/SKTM\/...`; pemeriksaan
         * terhadap teks mentah akan gagal walau datanya benar, atau lebih
         * buruk, LOLOS untuk data yang seharusnya tidak ada karena bentuknya
         * ikut berubah.
         */
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Publik/VerifikasiSurat')
            ->where('found', true)
            ->where('letter.letter_number', $letter->letter_number)
            ->where('letter.resident_name', 'Siti A***** R*****'));

        $props = $response->viewData('page')['props'];
        $serialized = json_encode($props, JSON_UNESCAPED_SLASHES) ?: '';

        $this->assertStringNotContainsString('3201010101800316', $serialized,
            'NIK lengkap bocor di halaman verifikasi publik.');
        $this->assertStringNotContainsString('keluarga yang tidak mampu', $serialized,
            'Isi surat bocor di halaman verifikasi publik.');
        $this->assertStringNotContainsString('Siti Aminah Rahayu', $serialized,
            'Nama lengkap tidak boleh tampil penuh di halaman publik.');
        $this->assertArrayNotHasKey('snapshot', $props['letter'],
            'Snapshot surat tidak boleh dikirim ke halaman publik.');
    }

    #[Test]
    #[TestDox('Kode verifikasi yang salah dicatat ke audit log sebagai percobaan menebak')]
    public function kode_verifikasi_salah_dicatat(): void
    {
        $this->get('/surat/verifikasi/KODEYANGSALAH1234567890ABCDEF')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'letter.verification_failed',
        ]);
    }

    /* ===================== Lingkup akses ===================== */

    #[Test]
    #[TestDox('Kepala Dusun tidak dapat melihat pengajuan dusun lain — 404, bukan 403')]
    public function lingkup_wilayah_menyembunyikan_pengajuan_dusun_lain(): void
    {
        $krajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $sidomulyo = Region::query()->where('name', 'Dusun Sidomulyo')->firstOrFail();

        $resident = $this->makeResident('3201010101800317', 'Warga Krajan');
        $request = $this->makeRequest($this->sktm, $resident);

        $kadusLain = $this->userWithRole(Role::KEPALA_DUSUN, $sidomulyo);
        $kadusSendiri = $this->userWithRole(Role::KEPALA_DUSUN, $krajan);

        /*
         * 404, BUKAN 403 (docs/06 §4). 403 memberi tahu penyerang bahwa objek
         * dengan ID tersebut memang ada — dan di sini "objek" adalah pengajuan
         * surat seorang warga.
         */
        $this->actingAs($kadusLain)
            ->get("/surat/{$request->id}")
            ->assertNotFound();

        $this->actingAs($kadusSendiri)
            ->get("/surat/{$request->id}")
            ->assertOk();

        // Tidak muncul pula di antrean.
        $this->actingAs($kadusLain)->get('/surat')->assertOk()
            ->assertDontSee($request->ticket_number);
    }

    #[Test]
    #[TestDox('Warga tidak dapat mengajukan surat untuk orang di luar KK-nya (RULE-11)')]
    public function warga_tidak_dapat_mengajukan_untuk_kk_lain(): void
    {
        $anggotaKkSendiri = $this->makeResident('3201010101800318', 'Warga Kk Satu');

        // Keluarga lain, penduduk lain.
        $keluargaLain = new Family;
        $keluargaLain->setKkNumber('3201010000009002');
        $keluargaLain->forceFill([
            'region_id' => $this->rt->id, 'address' => 'Jl. Surat 2',
        ])->save();

        $orangLain = $this->makeResident('3201010101800319', 'Warga Kk Dua');
        $orangLain->forceFill(['family_id' => $keluargaLain->id])->save();

        /*
         * Memakai peran Warga, BUKAN Operator.
         *
         * Operator SENGAJA tidak dibatasi wilayah (Role::REGION_LIMITED_ROLES
         * hanya memuat kepala_dusun): ia memang melayani seluruh desa dari
         * loket. Pembatasan yang berlaku pada pengajuan surat adalah
         * pembatasan KELUARGA pada peran Warga.
         */
        $warga = $this->userWithRole(Role::WARGA, residentId: $anggotaKkSendiri->id);

        /*
         * SEJAK SPRINT 7 antrean `/surat` dijaga middleware `petugas`: warga
         * mengajukan dan memantau lewat portalnya sendiri (`/warga/*`), dan
         * layar petugas bukan jalurnya. Lihat EnsureStaffQueue — ia menutup
         * kebocoran catatan internal yang ditemukan uji penetrasi Sprint 7.
         */
        $this->actingAs($warga)->post('/surat', [
            'letter_type_id' => $this->sktm->id,
            'resident_id' => $orangLain->id,
            'form' => ['keperluan' => 'Pengajuan beasiswa'],
        ])->assertForbidden();

        /*
         * Pembatasan KELUARGA itu sendiri tetap berlaku dan tidak bergantung
         * pada rute mana pun: ia ditegakkan ResidentRepository, yang dipakai
         * LetterRequestService untuk mencari pemohon. Penduduk di luar KK
         * menjadi 404 — bukan 403 — agar keberadaannya tidak terbocorkan
         * (docs/06 §4).
         *
         * Diuji lewat layanannya langsung, bukan lewat `/warga/ajukan`: jalur
         * daring menuntut unggahan berkas syarat lebih dulu (SKTM meminta
         * tiga), sehingga validasi itulah yang akan menjawab — bukan
         * pembatasan keluarga yang sedang diuji di sini.
         */
        $this->actingAs($warga);

        try {
            app(LetterRequestService::class)->create(
                AccessScope::forUser($warga),
                $this->sktm,
                $orangLain->id,
                'Pengajuan beasiswa',
                ['keperluan' => 'Pengajuan beasiswa'],
            );
            $this->fail('Warga dapat mengajukan surat untuk orang di luar KK-nya (RULE-11).');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertDatabaseCount('letter_requests', 0);

        // Untuk anggota KK-nya sendiri, pengajuan diterima.
        app(LetterRequestService::class)->create(
            AccessScope::forUser($warga),
            $this->sktm,
            $anggotaKkSendiri->id,
            'Pengajuan beasiswa',
            ['keperluan' => 'Pengajuan beasiswa'],
        );

        $this->assertDatabaseCount('letter_requests', 1);
    }

    #[Test]
    #[TestDox('Kepala Dusun tidak memiliki izin mengajukan surat sama sekali — 403')]
    public function kepala_dusun_tidak_dapat_mengajukan_surat(): void
    {
        $krajan = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $resident = $this->makeResident('3201010101800319', 'Warga Krajan Tiga');

        $kadus = $this->userWithRole(Role::KEPALA_DUSUN, $krajan);

        $this->actingAs($kadus)->post('/surat', [
            'letter_type_id' => $this->sktm->id,
            'resident_id' => $resident->id,
            'form' => ['keperluan' => 'Pengajuan beasiswa'],
        ])->assertForbidden();
    }

    /* ===================== Pembantu ===================== */

    /**
     * Penduduk uji.
     *
     * Hubungan keluarga bawaannya `anak`, BUKAN `kepala_keluarga`: indeks
     * `uq_families_single_head` hanya mengizinkan satu kepala keluarga per KK,
     * dan beberapa uji di kelas ini membuat lebih dari satu penduduk dalam
     * keluarga yang sama.
     */
    private function makeResident(string $nik, string $name): Resident
    {
        $resident = new Resident;
        $resident->setNik($nik);
        $resident->forceFill([
            'family_id' => $this->family->id,
            'region_id' => $this->rt->id,
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_place' => 'Sukamaju',
            'birth_date' => '1985-03-17',
            'gender' => 'P',
            'religion_code' => 'islam',
            'marital_status_code' => 'belum_kawin',
            'occupation_code' => 'petani',
            'family_relation_code' => 'anak',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        return $resident;
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeRequest(
        LetterType $type,
        Resident $resident,
        array $overrides = [],
    ): LetterRequest {
        return LetterRequest::query()->create([
            'letter_type_id' => $type->id,
            'ticket_number' => 'T'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
            'resident_id' => $resident->id,
            'channel' => 'walk_in',
            'purpose' => 'Pengajuan beasiswa',
            'form_data' => ['keperluan' => 'Pengajuan beasiswa'],
            'status' => LetterRequest::STATUS_SUBMITTED,
            'current_step' => 0,
            'submitted_at' => now(),
            'due_at' => now()->addDay(),
            ...$overrides,
        ]);
    }

    private function issueLetter(string $nik, string $name): Letter
    {
        $resident = $this->makeResident($nik, $name);
        $request = $this->makeRequest($this->sktm, $resident, [
            'status' => LetterRequest::STATUS_VERIFIED,
            'current_step' => 1,
        ]);

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect();

        return Letter::query()->where('request_id', $request->id)->firstOrFail();
    }
}
