<?php

declare(strict_types=1);

namespace Tests\Feature\Population;

use App\Models\AuditLog;
use App\Models\ImportJob;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Services\Import\ResidentImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Impor massal — FR-PDK-11, UC-04.
 */
final class ImportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    private function csv(string $body): UploadedFile
    {
        $header = 'NIK,Nama Lengkap,Tempat Lahir,Tanggal Lahir,Jenis Kelamin,'
            .'Nomor KK,Hubungan Keluarga,Alamat,RT,RW,Dusun,Agama,Status Perkawinan,'
            .'Pendidikan,Pekerjaan,Golongan Darah,Nama Ayah,Nama Ibu,Telepon,Kewarganegaraan';

        return UploadedFile::fake()->createWithContent(
            'data.csv', $header."\n".$body
        );
    }

    private function upload(UploadedFile $file): ImportJob
    {
        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->post('/kependudukan/impor', ['file' => $file])
            ->assertRedirect();

        /*
         * created_at hanya berpresisi detik, sehingga dua unggahan dalam satu
         * detik tidak dapat diurutkan dengannya. ID adalah UUID v7 yang
         * berurutan menurut waktu, jadi dipakai sebagai pemutus.
         */
        return ImportJob::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    #[Test]
    #[TestDox('Pra-validasi TIDAK menyimpan satu baris pun')]
    public function pra_validasi_tidak_menyimpan(): void
    {
        $before = Resident::query()->count();

        $job = $this->upload($this->csv(
            '3201011503900101,Budi Impor,Sukamaju,1990-03-15,L,,Kepala Keluarga,'
            .'Jl. Impor,,,,Islam,Belum Kawin,SLTA/Sederajat,Petani/Pekebun,,,,,'
        ));

        app(ResidentImporter::class)->preValidate($job);
        $job->refresh();

        $this->assertSame(ImportJob::STATUS_REVIEW, $job->status);
        $this->assertSame(1, $job->total_rows);
        $this->assertSame(1, $job->valid_rows);

        $this->assertSame($before, Resident::query()->count(),
            'Pra-validasi menyimpan data — seharusnya hanya memeriksa.');
    }

    #[Test]
    #[TestDox('Eksekusi menyimpan baris yang sah')]
    public function eksekusi_menyimpan(): void
    {
        $job = $this->upload($this->csv(
            '3201011503900102,Siti Impor,Sukamaju,1992-05-20,P,,Kepala Keluarga,'
            .'Jl. Impor 2,,,,Islam,Kawin Tercatat,SLTA/Sederajat,Petani/Pekebun,,,,,'
        ));

        $importer = app(ResidentImporter::class);
        $importer->preValidate($job);
        $importer->execute($job->refresh());
        $job->refresh();

        $this->assertSame(ImportJob::STATUS_DONE, $job->status);
        $this->assertSame(1, $job->created_count);
        $this->assertDatabaseHas('residents', ['full_name' => 'Siti Impor']);

        $resident = Resident::query()->where('full_name', 'Siti Impor')->firstOrFail();

        $this->assertSame('3201011503900102', $resident->revealNik());
        $this->assertSame('islam', $resident->religion_code,
            'Nilai teks "Islam" harus dipetakan ke kode referensi.');
    }

    #[Test]
    #[TestDox('Baris bermasalah dilaporkan dengan nomor baris, kolom, dan sebabnya')]
    public function galat_dilaporkan_rinci(): void
    {
        $job = $this->upload($this->csv(implode("\n", [
            '320101150390,NIK Pendek,Sukamaju,1990-03-15,L,,Kepala Keluarga,Jl. A,,,,,,,,,,,,',
            '3201011503900104,,Sukamaju,1990-03-15,L,,Anak,Jl. B,,,,,,,,,,,,',
            '3201011503900105,Tanggal Salah,Sukamaju,bukan-tanggal,L,,Anak,Jl. C,,,,,,,,,,,,',
            '3201011503900106,Gender Salah,Sukamaju,1990-03-15,X,,Anak,Jl. D,,,,,,,,,,,,',
        ])));

        app(ResidentImporter::class)->preValidate($job);
        $job->refresh();

        $this->assertSame(4, $job->total_rows);
        $this->assertSame(0, $job->valid_rows);

        $errors = collect($job->errors ?? []);

        $this->assertTrue($errors->contains(fn (array $e): bool => str_contains($e['pesan'], '12 angka')));
        $this->assertTrue($errors->contains(fn (array $e): bool => $e['kolom'] === 'Nama Lengkap'));
        $this->assertTrue($errors->contains(fn (array $e): bool => $e['kolom'] === 'Tanggal Lahir'));
        $this->assertTrue($errors->contains(fn (array $e): bool => $e['kolom'] === 'Jenis Kelamin'));

        // Nomor baris mengacu ke baris BERKAS (termasuk header), agar petugas
        // dapat langsung membukanya di Excel.
        $this->assertTrue($errors->every(fn (array $e): bool => $e['baris'] >= 2));
    }

    #[Test]
    #[TestDox('NIK ganda di dalam satu berkas terdeteksi beserta nomor baris pasangannya')]
    public function nik_ganda_dalam_berkas(): void
    {
        $job = $this->upload($this->csv(implode("\n", [
            '3201011503900107,Orang Satu,Sukamaju,1990-03-15,L,,Kepala Keluarga,Jl. A,,,,,,,,,,,,',
            '3201011503900107,Orang Dua,Sukamaju,1991-03-15,L,,Anak,Jl. A,,,,,,,,,,,,',
        ])));

        app(ResidentImporter::class)->preValidate($job);
        $job->refresh();

        $this->assertSame(1, $job->valid_rows);
        $this->assertSame(1, $job->invalid_rows);
        $this->assertStringContainsString(
            'sudah muncul di baris 2',
            collect($job->errors ?? [])->firstWhere('kolom', 'NIK')['pesan'] ?? ''
        );
    }

    #[Test]
    #[TestDox('Impor ulang MEMPERBARUI penduduk yang NIK-nya sudah ada')]
    public function impor_ulang_memperbarui(): void
    {
        $baris = '3201011503900108,Nama Awal,Sukamaju,1990-03-15,L,,Kepala Keluarga,'
            .'Jl. A,,,,Islam,Belum Kawin,,,,,,,';

        $job1 = $this->upload($this->csv($baris));
        $importer = app(ResidentImporter::class);
        $importer->preValidate($job1);
        $importer->execute($job1->refresh());

        $job2 = $this->upload($this->csv(str_replace('Nama Awal', 'Nama Diperbaiki', $baris)));
        $importer->preValidate($job2);
        $importer->execute($job2->refresh());
        $job2->refresh();

        $this->assertSame(0, $job2->created_count);
        $this->assertSame(1, $job2->updated_count);
        $this->assertDatabaseHas('residents', ['full_name' => 'Nama Diperbaiki']);
        $this->assertDatabaseMissing('residents', ['full_name' => 'Nama Awal']);
    }

    #[Test]
    #[TestDox('Berkas tanpa kolom wajib ditolak dengan menyebut kolom yang kurang')]
    public function kolom_wajib_hilang_ditolak(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'salah.csv', "Nama,Umur\nBudi,30"
        );

        $job = $this->upload($file);
        app(ResidentImporter::class)->preValidate($job);
        $job->refresh();

        $this->assertSame(ImportJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('NIK', (string) $job->failure_reason);
        $this->assertStringContainsString('template resmi', (string) $job->failure_reason);
    }

    #[Test]
    #[TestDox('Berkas tersimpan di luar web root dengan nama acak')]
    public function berkas_tersimpan_aman(): void
    {
        $job = $this->upload($this->csv(
            '3201011503900109,Aman,Sukamaju,1990-03-15,L,,Anak,Jl. A,,,,,,,,,,,,'
        ));

        $this->assertStringStartsWith('imports/', $job->storage_path);
        $this->assertStringNotContainsString('data.csv', $job->storage_path,
            'Nama berkas asli ikut menentukan jalur — dapat dipakai menebak URL.');
        $this->assertTrue(Storage::disk('local')->exists($job->storage_path));
    }

    #[Test]
    #[TestDox('Hanya pemegang izin penduduk.impor yang dapat mengimpor')]
    public function impor_terbatas_izin(): void
    {
        $file = $this->csv('3201011503900110,X,Y,1990-03-15,L,,Anak,Z,,,,,,,,,,,,');

        foreach ([Role::OPERATOR, Role::KEPALA_DUSUN, Role::PENGAWAS] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/kependudukan/impor')->assertForbidden();

            $this->actingAs($this->userWithRole($role))
                ->post('/kependudukan/impor', ['file' => $file])->assertForbidden();
        }

        $this->actingAs($this->userWithRole(Role::ADMIN_DESA))
            ->get('/kependudukan/impor')->assertOk();
    }

    #[Test]
    #[TestDox('Impor massal tercatat di audit log beserta jumlahnya')]
    public function impor_tercatat_audit(): void
    {
        $job = $this->upload($this->csv(
            '3201011503900111,Tercatat,Sukamaju,1990-03-15,L,,Anak,Jl. A,,,,,,,,,,,,'
        ));

        $importer = app(ResidentImporter::class);
        $importer->preValidate($job);
        $importer->execute($job->refresh());

        $this->assertDatabaseHas('audit_logs', ['action' => 'resident.imported']);

        $entry = AuditLog::query()
            ->where('action', 'resident.imported')->latest('id')->firstOrFail();

        $this->assertSame(1, $entry->context['ditambah']);
    }

    #[Test]
    #[TestDox('Wilayah TIDAK dibuat otomatis dari berkas impor')]
    public function wilayah_tidak_dibuat_otomatis(): void
    {
        $before = Region::query()->count();

        $job = $this->upload($this->csv(
            '3201011503900112,Wilayah Hantu,Sukamaju,1990-03-15,L,,Anak,Jl. A,999,999,Dusun Antah,,,,,,,,,'
        ));

        $importer = app(ResidentImporter::class);
        $importer->preValidate($job);
        $importer->execute($job->refresh());

        $this->assertSame($before, Region::query()->count(),
            'Impor membuat wilayah baru — satu salah ketik akan melahirkan '
            .'wilayah hantu yang mempengaruhi hak akses Kepala Dusun.');

        // Barisnya tetap masuk, hanya tanpa wilayah — lalu muncul di anomali.
        $resident = Resident::query()->where('full_name', 'Wilayah Hantu')->firstOrFail();
        $this->assertNull($resident->region_id);
    }
}
