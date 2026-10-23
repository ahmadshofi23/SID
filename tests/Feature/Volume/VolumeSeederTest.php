<?php

declare(strict_types=1);

namespace Tests\Feature\Volume;

use App\Models\Resident as ResidentModel;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Support\Pii\Nik;
use Database\Seeders\VolumeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penjaga penyemai volume — docs/09-testing-qa.md §4.5.
 *
 * MENGAPA PENYEMAI UJI PERLU DIUJI
 * --------------------------------
 * Penyemai ini menulis dengan sisipan massal, melewati Eloquent, lalu
 * MENGHITUNG SENDIRI nilai yang biasanya dihitung aplikasi: hash NIK,
 * `full_name_norm`, dan rantai hash audit log. Setiap satu dari ketiganya
 * dapat salah tanpa membuat apa pun gagal — dan akibatnya BUKAN penyemai yang
 * rusak, melainkan uji beban yang mengukur hal yang salah tanpa ada yang
 * tahu:
 *
 *   - hash NIK salah  -> pencarian NIK selalu nol baris, dan angka
 *                        "pencarian NIK ≤ 200 ms" menjadi angka kueri kosong;
 *   - `full_name_norm` salah -> kriteria `LIKE '%…%'` diukur di atas kolom
 *                        yang tidak pernah cocok dengan apa pun;
 *   - rantai hash salah -> `sid:verify-audit-chain` melaporkan insiden Sev-1
 *                        palsu, dan pengecekan keutuhan yang justru paling
 *                        perlu diuji pada 1,5 juta baris tidak dapat dipakai.
 *
 * Jumlahnya KECIL di sini dengan sengaja (200 penduduk, 300 audit log). Yang
 * diuji adalah kebenaran nilai yang dibangkitkan, bukan kecepatannya; 20.000
 * baris tidak akan membuktikan apa pun yang 200 baris belum membuktikan, dan
 * ia menambah belasan detik pada setiap jalannya rangkaian uji.
 */
final class VolumeSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFoundation();

        // Penyemai audit log menyalin nama pelaku dari tabel users.
        $this->userWithRole(Role::OPERATOR, withTwoFactor: false);
    }

    private function semai(
        int $residents = 200,
        int $auditRows = 300,
        int $letterRequests = 0,
        int $complaints = 20,
    ): void {
        (new VolumeSeeder)->run(
            residents: $residents,
            auditRows: $auditRows,
            letterRequests: $letterRequests,
            complaints: $complaints,
        );
    }

    #[Test]
    public function menyemai_penduduk_dan_keluarga_dalam_jumlah_yang_diminta(): void
    {
        $this->semai(residents: 200, auditRows: 0, complaints: 0);

        $this->assertSame(200, (int) DB::table('residents')->count());
        $this->assertGreaterThan(0, (int) DB::table('families')->count());

        // Setiap penduduk harus menempel pada KK dan wilayah yang nyata:
        // baris yang menggantung membuat daftar penduduk melanggar
        // preventLazyLoading dan menjawab 500 di tengah uji beban.
        $this->assertSame(0, (int) DB::table('residents')->whereNull('family_id')->count());
        $this->assertSame(0, (int) DB::table('residents')->whereNull('region_id')->count());
    }

    #[Test]
    public function nik_terenkripsi_dapat_ditemukan_lewat_hash_seperti_pencarian_sebenarnya(): void
    {
        $this->semai(residents: 50, auditRows: 0, complaints: 0);

        $row = DB::table('residents')->first(['nik_hash', 'nik_masked']);
        self::assertNotNull($row);

        $this->assertSame(
            1,
            (int) DB::table('residents')->where('nik_hash', $row->nik_hash)->count(),
            'Hash NIK harus unik dan dapat dicari — ini jalur yang dipakai '
            .'ResidentRepository::search() untuk pencarian NIK persis.'
        );

        $this->assertMatchesRegularExpression(
            '/^\d{4}•{8}\d{4}$/u',
            (string) $row->nik_masked,
            'NIK tersamar wajib berbentuk 3201••••••••1234 (RULE-07).'
        );
    }

    #[Test]
    public function nama_ternormalisasi_dapat_dicari_dengan_like_di_tengah(): void
    {
        $this->semai(residents: 200, auditRows: 0, complaints: 0);

        $nama = (string) DB::table('residents')->value('full_name_norm');
        $potongan = substr($nama, 1, 4);

        $this->assertStringNotContainsString(
            ' ',
            substr($nama, 0, 1),
            'Nama ternormalisasi tidak boleh berawalan spasi.'
        );

        $this->assertSame(
            mb_strtolower($nama),
            $nama,
            'full_name_norm wajib huruf kecil — kolom inilah yang dipindai '
            .'kriteria LIKE \'%…%\' docs/09 §4.5.'
        );

        $this->assertGreaterThan(
            0,
            (int) DB::table('residents')
                ->where('full_name_norm', 'like', '%'.$potongan.'%')
                ->count(),
            'Pencarian potongan nama harus menemukan setidaknya penduduk asalnya.'
        );
    }

    #[Test]
    public function nama_yang_dibangkitkan_cukup_beragam_untuk_diukur(): void
    {
        /*
         * MENGAPA UJI INI ADA — ini bekas kesalahan nyata.
         *
         * Versi pertama penyemai memilih nama dengan `$seq % jumlah`, dan
         * panjang siklusnya beresonansi dengan besar KK: 20.000 penduduk
         * hanya memakai 121 nama berbeda, satu nama dipakai 667 orang.
         * Tidak ada yang gagal — tetapi setiap pencarian mengenai ratusan
         * baris identik, sehingga selektivitas yang dihadapi petugas di
         * lapangan hilang dan angka `LIKE '%…%'` yang terukur tidak
         * menggambarkan apa pun.
         */
        $this->semai(residents: 400, auditRows: 0, complaints: 0);

        $unik = (int) DB::table('residents')->distinct()->count('full_name_norm');

        $this->assertGreaterThan(
            120,
            $unik,
            "Hanya {$unik} nama berbeda dari 400 penduduk. Tumpukan dengan "
            .'nama berulang membuat pengukuran pencarian tidak berarti.'
        );
    }

    #[Test]
    public function tepat_satu_kepala_keluarga_per_kk(): void
    {
        $this->semai(residents: 300, auditRows: 0, complaints: 0);

        /*
         * RULE-02 ditegakkan indeks unik `uq_families_single_head` lewat kolom
         * generated `family_head_key`. Penyisipan massal yang keliru akan
         * ditolak basis data — tetapi hanya bila ADA kepala keluarganya;
         * KK tanpa kepala sama sekali lolos tanpa suara, dan Buku Induk serta
         * setiap surat yang menyebut "kepala keluarga" kemudian kosong.
         */
        $tanpaKepala = DB::table('families')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('residents')
                    ->whereColumn('residents.family_id', 'families.id')
                    ->where('family_relation_code', 'kepala_keluarga');
            })
            ->count();

        $this->assertSame(0, (int) $tanpaKepala, 'Setiap KK wajib punya satu kepala keluarga.');
    }

    #[Test]
    public function rantai_hash_audit_log_tetap_utuh_setelah_sisipan_massal(): void
    {
        $this->semai(residents: 20, auditRows: 300, complaints: 0);

        $hasil = app(AuditLogger::class)->verifyChain();

        $this->assertTrue(
            $hasil['valid'],
            'Rantai hash audit log rusak setelah penyemaian massal '
            ."(baris {$hasil['broken_at']}). Penyemai menghitung rantainya "
            .'sendiri; bila AuditLog::computeHash() berubah, '
            .'VolumeSeeder::chainHash() wajib diubah bersamanya.'
        );

        $this->assertGreaterThanOrEqual(300, $hasil['checked']);
    }

    #[Test]
    public function dapat_dijalankan_dua_kali_tanpa_menabrak_nik_yang_sudah_ada(): void
    {
        /*
         * Uji tekanan menuntut volume yang dapat dinaikkan ("naikkan sampai
         * gagal"), dan cara termurahnya adalah menjalankan penyemai lagi.
         * NIK sintetis yang berulang akan ditolak `uq_residents_nik_active`
         * di tengah jalan — setelah separuh data masuk, yang jauh lebih
         * menyusahkan daripada ditolak di awal.
         */
        $this->semai(residents: 100, auditRows: 0, complaints: 0);
        $this->semai(residents: 100, auditRows: 0, complaints: 0);

        $this->assertSame(200, (int) DB::table('residents')->count());
        $this->assertSame(
            200,
            (int) DB::table('residents')->distinct()->count('nik_hash'),
            'Setiap penduduk wajib punya NIK berbeda.'
        );
    }

    #[Test]
    public function pengaduan_anonim_tidak_menyimpan_identitas_pelapor(): void
    {
        $this->semai(residents: 20, auditRows: 0, complaints: 40);

        /*
         * RULE-09. CHECK `chk_complaints_anon` sudah menolaknya di basis
         * data, jadi uji ini tidak menjaga kolomnya — ia menjaga agar
         * penyemai tidak "memperbaiki" kegagalan CHECK itu kelak dengan
         * mematikan anonimitasnya, dan membuat tumpukan uji beban tidak
         * lagi memuat satu pun pengaduan anonim untuk diukur.
         */
        $anonim = DB::table('complaints')->where('is_anonymous', true)->get();

        $this->assertGreaterThan(0, $anonim->count(), 'Harus ada pengaduan anonim.');

        foreach ($anonim as $pengaduan) {
            $this->assertNull($pengaduan->reporter_user_id);
            $this->assertNull($pengaduan->reporter_name);
            $this->assertNull($pengaduan->reporter_phone);
        }
    }

    #[Test]
    public function pengaduan_yang_ditolak_selalu_beralasan(): void
    {
        $this->semai(residents: 20, auditRows: 0, complaints: 40);

        // CHECK `chk_complaints_tolak_beralasan`: warga yang pengaduannya
        // ditolak berhak tahu mengapa. Penyemai versi pertama gagal di sini.
        $this->assertSame(
            0,
            (int) DB::table('complaints')
                ->where('status', 'ditolak')
                ->whereNull('resolution')
                ->count()
        );
    }

    #[Test]
    public function perintah_menolak_berjalan_di_produksi(): void
    {
        /*
         * PENJAGA TERPENTING DI SELURUH BERKAS INI.
         *
         * Perintah ini menyisipkan 20.000 penduduk fiktif ke tabel yang
         * menjadi dasar Buku Induk, statistik desa, dan setiap surat yang
         * terbit. Di produksi akibatnya tidak dapat dibatalkan dengan rapi:
         * penduduk fiktif sudah bercampur dengan penduduk nyata dalam satu
         * tabel, dan laporan yang sudah dicetak dengan angka itu tidak dapat
         * ditarik kembali.
         *
         * Penolakannya WAJIB terjadi SEBELUM apa pun ditulis dan tanpa opsi
         * pemaksa — karena itu uji ini juga memastikan tidak ada baris baru
         * yang muncul.
         */
        $this->app['env'] = 'production';

        $sebelum = (int) DB::table('residents')->count();

        $this->artisan('sid:seed-volume')
            ->expectsOutputToContain('menolak berjalan di APP_ENV=production')
            ->assertExitCode(1);

        $this->assertSame($sebelum, (int) DB::table('residents')->count());
    }

    #[Test]
    public function nik_yang_dibangkitkan_valid_secara_struktur(): void
    {
        /*
         * docs/09 §3: NIK sintetis wajib valid secara struktur (kode wilayah +
         * tanggal lahir + urut), bukan sekadar 16 angka.
         *
         * Akibatnya nyata pada apa yang diukur: `Nik::warnings()` menandai NIK
         * yang tanggal lahir atau jenis kelaminnya tidak cocok dengan kolomnya,
         * dan layar Anomali Data membaca penanda itu. NIK asal-asalan membuat
         * seluruh penduduk uji muncul sebagai anomali, sehingga halaman yang
         * ikut diukur uji beban bukan lagi halaman yang dipakai petugas.
         */
        $this->semai(residents: 300, auditRows: 0, complaints: 0);

        $residents = ResidentModel::query()
            ->get(['nik_enc', 'birth_date', 'gender'])
            ->all();

        $bermasalah = [];

        foreach ($residents as $resident) {
            $nik = (string) $resident->revealNik();
            $warnings = Nik::warnings(
                $nik,
                $resident->birth_date->toDateString(),
                $resident->gender,
            );

            if ($warnings !== []) {
                $bermasalah[] = $resident->nik_masked.': '.implode('; ', $warnings);
            }
        }

        $this->assertSame(
            [],
            array_slice($bermasalah, 0, 5),
            sprintf('%d dari %d NIK tidak valid secara struktur.', count($bermasalah), count($residents))
        );
    }

    #[Test]
    public function memuat_kasus_tepi_usia_yang_diminta_docs_09(): void
    {
        $this->semai(residents: 1_500, auditRows: 0, complaints: 0);

        $this->assertGreaterThan(
            0,
            (int) DB::table('residents')->where('birth_date', '<', now()->subYears(100))->count(),
            'Harus ada penduduk berusia di atas 100 tahun (docs/09 §3).'
        );

        $this->assertGreaterThan(
            0,
            (int) DB::table('residents')->where('birth_date', '>', now()->subMonths(1))->count(),
            'Harus ada bayi berusia di bawah satu bulan (docs/09 §3).'
        );

        $this->assertGreaterThan(
            0,
            (int) DB::table('residents')->whereRaw('CHAR_LENGTH(full_name) > 100')->count(),
            'Harus ada nama lebih dari 100 karakter (docs/09 §3).'
        );
    }
}
