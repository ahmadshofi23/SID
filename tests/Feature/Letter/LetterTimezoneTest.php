<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Models\Family;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Region;
use App\Models\Resident;
use App\Models\Role;
use App\Services\Letter\LetterNumberService;
use App\Support\NameNormalizer;
use App\Support\VillageTime;
use Database\Seeders\LetterTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Penomoran & tanggal surat memakai kalender desa, bukan UTC.
 *
 * MENGAPA KELAS INI ADA: aplikasi menyimpan waktu dalam UTC, sementara
 * kantor desa bekerja menurut WIB/WITA/WIT. Selisihnya tidak terlihat
 * sepanjang tahun — dan muncul tepat di pergantian tahun, sebagai nomor
 * surat ganda. Bug ini ditemukan saat menelusuri alur di peramban, bukan
 * oleh uji; uji di bawah memastikan ia tidak kembali.
 */
final class LetterTimezoneTest extends TestCase
{
    use DatabaseTransactions;

    private LetterType $sktm;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seed(LetterTypeSeeder::class);

        $dusun = Region::query()->where('name', 'Dusun Krajan')->firstOrFail();
        $rw = Region::query()->where('parent_id', $dusun->id)->firstOrFail();
        $rt = Region::query()->where('parent_id', $rw->id)->firstOrFail();

        $family = new Family;
        $family->setKkNumber('3201010000008001');
        $family->forceFill(['region_id' => $rt->id, 'address' => 'Jl. Zona 1'])->save();

        $resident = new Resident;
        $resident->setNik('3201010101800401');
        $resident->forceFill([
            'family_id' => $family->id,
            'region_id' => $rt->id,
            'full_name' => 'Pemohon Zona',
            'full_name_norm' => NameNormalizer::normalize('Pemohon Zona'),
            'birth_place' => 'Sukamaju',
            'birth_date' => '1985-03-17',
            'gender' => 'P',
            'marital_status_code' => 'belum_kawin',
            'family_relation_code' => 'anak',
            'citizenship' => 'WNI',
            'status' => Resident::STATUS_ACTIVE,
        ])->save();

        $this->resident = $resident;
        $this->sktm = LetterType::query()->where('code', 'SKTM')->firstOrFail();
    }

    /**
     * Saat kritis: 31 Desember 2026 pukul 19.00 UTC = 1 Januari 2027 pukul
     * 02.00 WIB. Ini jam kerja yang tidak lazim, tetapi terjadi — dan cukup
     * sekali untuk menghasilkan dua surat bernomor sama.
     */
    private const PERGANTIAN_TAHUN_UTC = '2026-12-31 19:00:00';

    #[Test]
    #[TestDox('Nomor surat memakai tahun kalender desa, bukan tahun UTC')]
    public function nomor_surat_memakai_tahun_kalender_desa(): void
    {
        $issuedAt = Carbon::parse(self::PERGANTIAN_TAHUN_UTC, 'UTC');

        $this->assertSame(2026, (int) $issuedAt->format('Y'),
            'Prakondisi: di UTC saat ini masih tahun 2026.');
        $this->assertSame(2027, VillageTime::year($issuedAt),
            'Prakondisi: di kantor desa sudah tahun 2027.');

        $number = app(LetterNumberService::class)
            ->format($this->sktm, 1, $issuedAt);

        $this->assertStringContainsString('2027', $number,
            'Nomor surat menyebut tahun UTC — buku agenda desa mencatatnya '
            .'sebagai surat pertama tahun berikutnya.');
        $this->assertStringContainsString('/I/', $number,
            'Bulan Romawi harus I (Januari), bukan XII.');
    }

    #[Test]
    #[TestDox('Penghitung nomor memakai tahun desa yang sama dengan nomor tercetak')]
    public function penghitung_dan_nomor_memakai_tahun_yang_sama(): void
    {
        $issuedAt = Carbon::parse(self::PERGANTIAN_TAHUN_UTC, 'UTC');

        DB::transaction(fn () => app(LetterNumberService::class)
            ->allocate($this->sktm, $issuedAt));

        $this->assertDatabaseHas('letter_counters', [
            'letter_type_id' => $this->sktm->id,
            'year' => 2027,
            'last_number' => 1,
        ]);

        /*
         * Inilah inti bug-nya: bila penghitung memakai 2026 sementara nomor
         * tercetak menyebut 2027, maka surat pertama Januari memperoleh
         * nomor lanjutan tahun lalu — atau, setelah penghitung 2027 dibuat
         * oleh surat berikutnya, nomor 1 terbit DUA KALI.
         */
        $this->assertDatabaseMissing('letter_counters', [
            'letter_type_id' => $this->sktm->id,
            'year' => 2026,
        ]);
    }

    #[Test]
    #[TestDox('issued_year pada surat terbit sama dengan tahun penghitung (jaring pengaman uq_letters_seq)')]
    public function issued_year_sejalan_dengan_tahun_penghitung(): void
    {
        Carbon::setTestNow(Carbon::parse(self::PERGANTIAN_TAHUN_UTC, 'UTC'));

        try {
            $letter = $this->issueLetter();

            $this->assertSame(2027, $letter->issued_year,
                'issued_year mengikuti UTC — indeks unik uq_letters_seq akan '
                .'menjaga pasangan tahun yang salah dan nomor ganda lolos.');

            $this->assertDatabaseHas('letter_counters', [
                'letter_type_id' => $this->sktm->id,
                'year' => $letter->issued_year,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    #[TestDox('Tanggal yang tercetak di surat memakai kalender desa')]
    public function tanggal_tercetak_memakai_kalender_desa(): void
    {
        Carbon::setTestNow(Carbon::parse(self::PERGANTIAN_TAHUN_UTC, 'UTC'));

        try {
            $letter = $this->issueLetter();

            $this->assertSame('1 Januari 2027', $letter->snapshot['tanggal_terbit_panjang'],
                'Surat tercetak bertanggal 31 Desember padahal nomornya menyebut '
                .'Januari tahun berikutnya — dokumen bertentangan dengan dirinya sendiri.');

            // Masa berlaku SKTM 3 bulan, dihitung dari tanggal lokal.
            $this->assertSame('2027-04-01', $letter->valid_until?->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    #[TestDox('Batas waktu SLA adalah akhir hari kerja di kantor desa, bukan akhir hari UTC')]
    public function batas_waktu_sla_memakai_akhir_hari_di_desa(): void
    {
        // Kamis 15 Oktober 2026, 10.00 UTC = 17.00 WIB.
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC'));

        try {
            $admin = $this->userWithRole(Role::ADMIN_DESA);

            $this->actingAs($admin)->post('/surat', [
                'letter_type_id' => $this->sktm->id,
                'resident_id' => $this->resident->id,
                'form' => ['keperluan' => 'Pengajuan beasiswa'],
            ])->assertRedirect();

            $request = LetterRequest::query()->firstOrFail();
            $due = VillageTime::local($request->due_at);

            $this->assertSame('2026-10-16', $due->toDateString(),
                'SLA 1 hari kerja dari Kamis jatuh pada Jumat.');
            $this->assertSame('23:59:59', $due->format('H:i:s'),
                'Batas waktu harus akhir hari di kantor desa. Akhir hari UTC '
                .'jatuh pukul 07.00 pagi berikutnya di Indonesia, sehingga surat '
                .'yang terlambat tercatat tepat waktu.');
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    #[TestDox('SLA melewati akhir pekan: pengajuan Jumat jatuh tempo Senin, bukan Sabtu')]
    public function sla_melewati_akhir_pekan(): void
    {
        // Jumat 16 Oktober 2026.
        $jumat = Carbon::parse('2026-10-16 10:00:00', 'UTC');

        $due = VillageTime::local(VillageTime::endOfWorkingDay($jumat, 1));

        $this->assertSame('2026-10-19', $due->toDateString());
        $this->assertSame('Monday', $due->format('l'));
    }

    private function issueLetter(): Letter
    {
        $request = LetterRequest::query()->create([
            'letter_type_id' => $this->sktm->id,
            'ticket_number' => 'T-ZONA-'.strtoupper(bin2hex(random_bytes(3))),
            'resident_id' => $this->resident->id,
            'channel' => 'walk_in',
            'purpose' => 'Pengajuan beasiswa',
            'form_data' => ['keperluan' => 'Pengajuan beasiswa'],
            'status' => LetterRequest::STATUS_VERIFIED,
            'current_step' => 1,
            'submitted_at' => now(),
        ]);

        $admin = $this->userWithRole(Role::ADMIN_DESA);

        $this->actingAs($admin)
            ->post("/surat/{$request->id}/terbitkan")
            ->assertRedirect();

        return Letter::query()->where('request_id', $request->id)->firstOrFail();
    }
}
