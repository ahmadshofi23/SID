<?php

declare(strict_types=1);

namespace Tests\Feature\Letter;

use App\Models\LetterType;
use App\Services\Letter\LetterNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TC-SRT-08 — penomoran surat bebas duplikat & bebas celah.
 * docs/09-testing-qa.md §5. KRITIS: kegagalan di sini memblokir rilis.
 *
 * MENGAPA UJI INI MEMAKAI PROSES TERPISAH (pcntl_fork):
 *
 * Uji yang memanggil allocate() dua puluh kali berturut-turut dalam satu
 * proses akan SELALU lulus — termasuk bila penguncian dihapus seluruhnya.
 * Ia hanya membuktikan bahwa penambahan satu demi satu berfungsi, yang tidak
 * pernah menjadi masalahnya. Bug penomoran hanya muncul ketika dua transaksi
 * BENAR-BENAR bersamaan, dan itu menuntut lebih dari satu koneksi basis data.
 *
 * Karena itu setiap "petugas" di sini adalah proses sistem operasi sendiri
 * dengan koneksinya sendiri. Uji ini sengaja lebih lambat dan lebih rumit
 * daripada uji biasa; nomor surat ganda adalah temuan audit administrasi
 * desa, dan itu layak dibayar dengan beberapa detik di CI.
 *
 * TIDAK memakai RefreshDatabase: transaksi pembungkusnya tidak terlihat oleh
 * proses anak. Pembersihan dilakukan manual.
 */
#[Group('concurrency')]
final class LetterNumberingConcurrencyTest extends TestCase
{
    private LetterType $type;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Ekstensi pcntl tidak tersedia — uji konkurensi dilewati.');
        }

        $this->seedFoundation();
        $this->truncateLetterTables();
        $this->setCounterMode('per_type');
        $this->type = $this->makeType('UJI-A');
    }

    protected function tearDown(): void
    {
        /*
         * Mode penghitung DIPULIHKAN, bukan dibiarkan.
         *
         * Uji ini tidak memakai RefreshDatabase (lihat keterangan kelas),
         * sehingga baris `settings` bertahan antar uji DAN antar jalannya
         * suite. Tanpa pemulihan ini, satu uji mode tunggal membuat seluruh
         * uji penomoran lain memakai satu deret bersama — selamanya, sampai
         * basis data uji dibuat ulang.
         */
        $this->setCounterMode('per_type');
        $this->truncateLetterTables();

        parent::tearDown();
    }

    #[Test]
    public function dua_puluh_penerbitan_bersamaan_menghasilkan_nomor_unik_tanpa_celah(): void
    {
        $numbers = $this->allocateInParallel($this->type->id, workers: 20);

        $this->assertCount(20, $numbers, 'Setiap petugas harus mendapat satu nomor.');
        $this->assertSame(
            range(1, 20),
            $numbers,
            'Nomor wajib unik dan berurut 1–20 tanpa celah. Nomor ganda atau '
            .'terlewat adalah temuan audit administrasi desa.'
        );
    }

    /**
     * Varian (b) — jebakan utama ADR-001.
     *
     * Baris penghitung untuk tahun ini BELUM ADA. Di MySQL,
     * `SELECT ... FOR UPDATE` pada baris yang tidak ada TIDAK mengunci apa
     * pun, sehingga tanpa `INSERT ... ON DUPLICATE KEY` lebih dulu, semua
     * proses akan membaca "belum ada" dan mengambil nomor 1 bersama-sama.
     *
     * Inilah satu-satunya uji yang membedakan implementasi yang benar dari
     * yang tampak benar.
     */
    #[Test]
    public function penghitung_tahun_baru_yang_belum_ada_tetap_menghasilkan_nomor_unik(): void
    {
        $this->assertSame(
            0,
            DB::table('letter_counters')->count(),
            'Prakondisi: penghitung harus benar-benar kosong.'
        );

        $numbers = $this->allocateInParallel($this->type->id, workers: 10);

        $this->assertSame(
            range(1, 10),
            $numbers,
            'Sepuluh permintaan bersamaan pada penghitung yang belum ada wajib '
            .'tetap menghasilkan nomor unik (ADR-001).'
        );
    }

    /** Varian (c) — dua jenis surat memiliki deret terpisah. */
    #[Test]
    public function jenis_surat_berbeda_memiliki_deret_nomor_terpisah(): void
    {
        $other = $this->makeType('UJI-B');

        $first = $this->allocateInParallel($this->type->id, workers: 5);
        $second = $this->allocateInParallel($other->id, workers: 5);

        $this->assertSame(range(1, 5), $first);
        $this->assertSame(
            range(1, 5),
            $second,
            'Jenis surat kedua harus memulai deretnya sendiri dari 1.'
        );
    }

    /** Varian (d) — pola nomor tunggal: satu deret untuk semua jenis. */
    #[Test]
    public function mode_nomor_tunggal_memakai_satu_deret_untuk_semua_jenis(): void
    {
        $this->setCounterMode('single');

        $other = $this->makeType('UJI-B');

        $first = $this->allocateInParallel($this->type->id, workers: 5);
        $second = $this->allocateInParallel($other->id, workers: 5);

        $this->assertSame(range(1, 5), $first);
        $this->assertSame(
            range(6, 10),
            $second,
            'Mode tunggal: jenis surat kedua melanjutkan deret yang sama.'
        );

        $this->assertSame(
            1,
            DB::table('letter_counters')->count(),
            'Mode tunggal hanya boleh memakai satu baris penghitung.'
        );
    }

    /**
     * Varian (a) — transaksi yang dibatalkan TIDAK membuang nomor.
     *
     * Ini adalah alasan pokok penomoran tidak memakai AUTO_INCREMENT:
     * AUTO_INCREMENT tidak transaksional, sehingga setiap penerbitan yang
     * gagal meninggalkan lubang permanen di deret nomor.
     */
    #[Test]
    public function transaksi_yang_dibatalkan_tidak_membuang_nomor(): void
    {
        $service = app(LetterNumberService::class);

        // Tiga penerbitan berhasil.
        foreach (range(1, 3) as $ignored) {
            DB::transaction(fn () => $service->allocate($this->type));
        }

        // Satu penerbitan gagal di tengah — misalnya perenderan template error.
        try {
            DB::transaction(function () use ($service): void {
                $service->allocate($this->type);

                throw new RuntimeException('kegagalan disengaja');
            });
        } catch (RuntimeException) {
            // diharapkan
        }

        [$sequence] = DB::transaction(fn () => $service->allocate($this->type));

        $this->assertSame(
            4,
            $sequence,
            'Nomor yang transaksinya dibatalkan harus dipakai ulang — deret '
            .'surat desa tidak boleh berlubang.'
        );
    }

    #[Test]
    public function pengambilan_nomor_di_luar_transaksi_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('harus dipanggil di dalam transaksi');

        app(LetterNumberService::class)->allocate($this->type);
    }

    #[Test]
    public function pola_nomor_disusun_sesuai_token(): void
    {
        $service = app(LetterNumberService::class);
        $issuedAt = Carbon::create(2026, 10, 9);

        $this->type->number_pattern = '{urut:3}/{kode}/{kode_desa}/{bulan_romawi}/{tahun}';

        $this->assertSame(
            '145/UJI-A/1210032002/X/2026',
            $service->format($this->type, 145, $issuedAt),
        );

        // Token kosong tidak boleh meninggalkan pemisah ganda.
        $this->type->number_pattern = '{klasifikasi}/{urut}/{kode_desa}/{tahun}';
        $this->type->classification_code = null;

        $this->assertSame(
            '7/1210032002/2026',
            $service->format($this->type, 7, $issuedAt),
        );
    }

    /* ===================== Pembantu ===================== */

    /**
     * Jalankan `$workers` pengambilan nomor di proses terpisah, bersamaan.
     *
     * @return array<int, int> nomor urut yang terbit, terurut
     */
    private function allocateInParallel(string $letterTypeId, int $workers): array
    {
        $before = (int) DB::table('letter_counters')->sum('last_number');

        /*
         * Berkas penanda dipakai sebagai gerbang start.
         *
         * Tanpanya, proses yang lebih dulu siap sudah selesai sebelum proses
         * terakhir lahir, sehingga tidak ada yang benar-benar bersamaan dan
         * uji ini kembali menjadi uji berurutan yang menipu.
         */
        $gate = tempnam(sys_get_temp_dir(), 'sid-gate-');
        $resultDir = sys_get_temp_dir().'/sid-seq-'.bin2hex(random_bytes(6));
        mkdir($resultDir);

        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Gagal membuat proses anak untuk uji konkurensi.');
            }

            if ($pid === 0) {
                $this->childAllocate($letterTypeId, $gate, $resultDir.'/'.$i);
            }

            $pids[] = $pid;
        }

        // Semua anak sudah lahir dan menunggu; buka gerbang.
        usleep(200_000);
        unlink($gate);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $sequences = [];

        foreach (glob($resultDir.'/*') ?: [] as $file) {
            $value = trim((string) file_get_contents($file));

            $this->assertMatchesRegularExpression(
                '/^\d+$/',
                $value,
                'Salah satu petugas gagal mendapat nomor: '.$value
            );

            $sequences[] = (int) $value;
            unlink($file);
        }

        rmdir($resultDir);

        sort($sequences);

        /*
         * Diperiksa SETELAH nomornya terkumpul agar pesan galatnya
         * menyebutkan nomor yang benar-benar terbit. Tanpa itu, kegagalan
         * hanya berbunyi "penghitung tidak bertambah" dan penyebab
         * sebenarnya — dua puluh petugas mendapat nomor 1 — tidak terlihat.
         */
        $this->assertSame(
            $workers,
            (int) DB::table('letter_counters')->sum('last_number') - $before,
            'Penghitung harus bertambah tepat sebanyak nomor yang terbit. '
            .'Nomor yang terbit: '.implode(', ', $sequences)
        );

        return $sequences;
    }

    /**
     * Badan proses anak. TIDAK PERNAH kembali — selalu exit().
     *
     * Proses anak mewarisi koneksi PDO induk; ia WAJIB memutusnya lebih dulu
     * agar mendapat koneksinya sendiri. Dua proses yang berbagi satu socket
     * MySQL akan saling merusak protokolnya, dan galatnya menyesatkan
     * (tampak seperti kerusakan basis data, bukan masalah uji).
     */
    private function childAllocate(string $letterTypeId, string $gate, string $outFile): never
    {
        try {
            DB::purge();

            while (file_exists($gate)) {
                usleep(2_000);
            }

            $type = LetterType::query()->findOrFail($letterTypeId);

            [$sequence] = DB::transaction(
                fn () => app(LetterNumberService::class)->allocate($type)
            );

            file_put_contents($outFile, (string) $sequence);
        } catch (\Throwable $e) {
            file_put_contents($outFile, 'GALAT: '.$e->getMessage());
        }

        // exit(), bukan return: proses anak tidak boleh melanjutkan PHPUnit.
        exit(0);
    }

    private function setCounterMode(string $mode): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'letter.counter_mode'],
            ['value' => json_encode($mode), 'updated_at' => now()]
        );

        cache()->forget('settings.all');
    }

    private function makeType(string $code): LetterType
    {
        return LetterType::query()->create([
            'code' => $code,
            'name' => 'Jenis Uji '.$code,
            'title_on_letter' => 'SURAT UJI',
            'template_html' => '<p>Isi uji.</p>',
            'number_pattern' => '{urut}/{kode}/{kode_desa}/{bulan_romawi}/{tahun}',
            'approval_flow' => [],
            'requirements' => [],
            'form_schema' => [],
            'sla_days' => 1,
            'is_active' => true,
        ]);
    }

    private function truncateLetterTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['letters', 'letter_request_logs', 'letter_requests', 'letter_counters', 'letter_types'] as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
