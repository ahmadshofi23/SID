<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Models\LetterType;
use App\Models\Role;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Editor jenis surat & template — FR-SRT-01, FR-SRT-02.
 */
final class LetterTypeEditorTest extends TestCase
{
    use DatabaseTransactions;

    private LetterType $sktm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $this->sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => $this->sktm->name,
            'title_on_letter' => $this->sktm->title_on_letter,
            'classification_code' => null,
            'description' => $this->sktm->description,
            'number_pattern' => $this->sktm->number_pattern,
            'sla_days' => 1,
            'validity_months' => 3,
            'fee' => 0,
            'is_active' => true,
            'is_public' => true,
            'requires_field_check' => false,
            'warning_note' => null,
            'template_html' => $this->sktm->template_html,
            ...$overrides,
        ];
    }

    #[Test]
    #[TestDox('Template dengan variabel tak dikenal ditolak, dengan menyebut variabelnya (FR-SRT-02)')]
    public function variabel_tak_dikenal_ditolak(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $response = $this->actingAs($admin)->put(
            "/jenis-surat/{$this->sktm->id}",
            $this->payload(['template_html' => '<p>Penghasilan: {{penghasilan_ayah}}</p>']),
        );

        $response->assertSessionHasErrors('template_html');

        $this->assertStringContainsString(
            'penghasilan_ayah',
            $this->errorMessage($response, 'template_html'),
            'Pesan galat harus menyebut variabel yang salah; "ada kesalahan" '
            .'tidak cukup untuk memperbaikinya.'
        );

        $this->assertSame(
            $this->sktm->template_html,
            $this->sktm->fresh()->template_html,
            'Template tersimpan walau ditolak.',
        );
    }

    #[Test]
    #[TestDox('Variabel isian dinamis milik jenis surat itu sendiri diterima')]
    public function variabel_isian_dinamis_diterima(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->put(
            "/jenis-surat/{$this->sktm->id}",
            $this->payload([
                'template_html' => '<p>Keperluan {{form.keperluan}} untuk {{nama}}.</p>',
            ]),
        )->assertSessionHasNoErrors()->assertRedirect();

        $this->assertStringContainsString(
            '{{form.keperluan}}',
            $this->sktm->fresh()->template_html,
        );
    }

    #[Test]
    #[TestDox('Pola nomor tanpa {urut} ditolak — surat kedua tidak akan dapat terbit')]
    public function pola_nomor_tanpa_urut_ditolak(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->put(
            "/jenis-surat/{$this->sktm->id}",
            $this->payload(['number_pattern' => '{kode}/{kode_desa}/{tahun}']),
        )->assertSessionHasErrors('number_pattern');

        // Bentuk berpadding juga sah.
        $this->actingAs($admin)->put(
            "/jenis-surat/{$this->sktm->id}",
            $this->payload(['number_pattern' => '{urut:4}/{kode}/{tahun}']),
        )->assertSessionHasNoErrors();
    }

    #[Test]
    #[TestDox('Skrip di dalam template dibuang saat disimpan, dan petugas diberi tahu')]
    public function skrip_dibuang_dan_diberitahukan(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        /*
         * Operator memegang izin `jenis_surat.kelola` di sebagian desa.
         * Tanpa pembersihan, ia dapat menitipkan skrip yang berjalan di
         * peramban Kepala Desa saat layar persetujuan dibuka — peningkatan
         * hak akses, bukan sekadar tampilan rusak.
         */
        $response = $this->actingAs($admin)->put(
            "/jenis-surat/{$this->sktm->id}",
            $this->payload([
                'template_html' => '<p onclick="alert(1)">Halo {{nama}}</p>'
                    .'<script>fetch("/x")</script>',
            ]),
        );

        $response->assertSessionHasNoErrors()->assertRedirect();

        $saved = $this->sktm->fresh()->template_html;

        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('onclick', $saved);
        $this->assertStringContainsString('Halo {{nama}}', $saved,
            'Isi yang sah ikut terbuang — petugas kehilangan redaksinya.');

        // Pembersihan diam-diam membingungkan: petugas harus tahu.
        $response->assertSessionHas('warning');
    }

    #[Test]
    #[TestDox('Pratinjau mengisi isian dinamis dengan contoh, bukan meninggalkan kalimat terpotong')]
    public function pratinjau_mengisi_isian_dinamis(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $response = $this->actingAs($admin)->postJson('/jenis-surat/pratinjau', [
            'template_html' => $this->sktm->template_html,
            'letter_type_id' => $this->sktm->id,
        ]);

        $response->assertOk();

        $html = $response->json('html');

        $this->assertIsString($html);
        $this->assertStringContainsString('PENGAJUAN BEASISWA', $html,
            'Pratinjau berbunyi "untuk keperluan: ." — petugas tidak dapat '
            .'menilai redaksi yang sedang disuntingnya.');
        $this->assertStringNotContainsString('{{', $html,
            'Variabel tersisa mentah di hasil pratinjau.');
    }

    #[Test]
    #[TestDox('Pratinjau memakai data fiktif, bukan penduduk nyata')]
    public function pratinjau_tidak_memakai_data_warga(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $html = $this->actingAs($admin)->postJson('/jenis-surat/pratinjau', [
            'template_html' => '<p>{{nama}} — {{nik}}</p>',
            'letter_type_id' => $this->sktm->id,
        ])->json('html');

        $this->assertIsString($html);
        $this->assertStringContainsString('SITI AMINAH', $html);
        $this->assertStringContainsString('3201234567890123', $html,
            'Pratinjau harus memakai NIK contoh yang tetap, bukan NIK warga.');
    }

    #[Test]
    #[TestDox('Jenis surat yang sudah disunting petugas tidak ditimpa seeder')]
    public function seeder_tidak_menimpa_suntingan_petugas(): void
    {
        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)->put(
            "/jenis-surat/{$this->sktm->id}",
            $this->payload(['template_html' => '<p>Redaksi khas desa ini.</p>']),
        )->assertSessionHasNoErrors();

        // Pembaruan versi menjalankan seeder lagi.
        $this->seed(LetterTypeSeeder::class);

        $this->assertStringContainsString(
            'Redaksi khas desa ini.',
            $this->sktm->fresh()->template_html,
            'Seeder menimpa template yang sudah disesuaikan Sekretaris Desa.',
        );
    }

    #[Test]
    #[TestDox('Operator tanpa izin jenis_surat.kelola tidak dapat menyunting template')]
    public function operator_tidak_dapat_menyunting_template(): void
    {
        $operator = $this->userWithRole(Role::OPERATOR);

        $this->actingAs($operator)
            ->get("/jenis-surat/{$this->sktm->id}/ubah")
            ->assertForbidden();

        $this->actingAs($operator)
            ->put("/jenis-surat/{$this->sktm->id}", $this->payload())
            ->assertForbidden();
    }
}
