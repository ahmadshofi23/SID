<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;
use Throwable;

/**
 * Perangkaian audit log di bawah penulisan bersamaan — KEP-020,
 * docs/23-uji-beban-sprint-7.md §7 butir 1.
 *
 * MENGAPA UJI INI MEMAKAI PROSES TERPISAH (pcntl_fork), dengan alasan yang
 * sama seperti LetterNumberingConcurrencyTest: deadlock menuntut dua transaksi
 * yang BENAR-BENAR bersamaan, dan itu menuntut lebih dari satu koneksi basis
 * data. Uji yang memanggil AuditLogger sepuluh kali berturut-turut dalam satu
 * proses akan selalu lulus — termasuk bila seluruh penguncian dihapus.
 *
 * YANG DITEMUKAN UJI BEBAN, dan mengapa ia pantas mendapat berkas sendiri:
 * `AuditLogger` merantai setiap baris dengan `ORDER BY id DESC LIMIT 1 FOR
 * UPDATE`. Pada InnoDB, kunci itu mencakup celah sesudah baris terakhir, dan
 * gap lock SALING KOMPATIBEL — dua transaksi dapat memegangnya bersamaan.
 * Begitu keduanya hendak menyisipkan ke dalam celah itu, kunci
 * insert-intention yang diperlukan bertentangan dengan gap lock transaksi
 * lain, keduanya menunggu, dan MySQL membunuh salah satunya dengan galat
 * 1213. Bagi petugas yang mengalaminya ia berupa HTTP 500.
 *
 * TIDAK memakai RefreshDatabase maupun DatabaseTransactions: transaksi
 * pembungkusnya tidak terlihat oleh proses anak. Pembersihan manual.
 */
#[Group('concurrency')]
final class AuditChainConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Ekstensi pcntl tidak tersedia — uji konkurensi dilewati.');
        }

        $this->seedFoundation();
        DB::table('audit_logs')->truncate();
        DB::statement('INSERT INTO audit_chain_lock (id) VALUES (1)
                       ON DUPLICATE KEY UPDATE id = id');
    }

    protected function tearDown(): void
    {
        /*
         * Audit log dibersihkan, bukan dibiarkan: uji ini tidak memakai
         * RefreshDatabase, dan baris ber-hash palsu yang ditinggalkan di sini
         * akan membuat setiap uji rantai hash sesudahnya gagal — termasuk
         * yang berjalan di suite lain.
         */
        DB::table('audit_logs')->truncate();

        parent::tearDown();
    }

    /**
     * Invarian produksi: penulisan audit bersamaan semuanya BERHASIL, dan
     * rantainya tetap satu.
     *
     * Dua hal diperiksa sekaligus karena keduanya dapat dirusak oleh
     * perubahan yang sama. Menghapus mutex-nya mengembalikan deadlock;
     * menghapus `FOR UPDATE` di bawahnya membuat dua permintaan membaca ujung
     * rantai yang sama dan rantainya BERCABANG — dua baris dengan `prev_hash`
     * yang sama, yang justru hal yang dijaga ADR-005. Uji ini menolak
     * keduanya.
     */
    #[Test]
    #[TestDox('Dua belas pencatatan audit bersamaan semuanya berhasil & rantainya tetap utuh')]
    public function pencatatan_bersamaan_berhasil_dan_rantai_tetap_utuh(): void
    {
        $results = $this->runInParallel(workers: 12, scenario: 'logger');

        $this->assertSame(
            [],
            array_filter($results, static fn (string $r): bool => $r !== 'ok'),
            'Setiap pencatatan audit yang bersamaan harus berhasil. Kegagalan '
            .'di sini berupa HTTP 500 di hadapan petugas.'
        );

        $this->assertSame(
            12,
            AuditLog::query()->count(),
            'Setiap proses harus meninggalkan tepat satu baris.'
        );

        /*
         * Dihitung di PHP dan bukan dengan COUNT(DISTINCT prev_hash): baris
         * pertama rantai ber-prev_hash NULL, dan COUNT(DISTINCT) di SQL
         * MENGABAIKAN NULL — sehingga 12 baris yang benar akan terbaca 11
         * dan ujinya gagal atas data yang sehat.
         */
        $links = DB::table('audit_logs')->orderBy('id')->pluck('prev_hash')
            ->map(static fn (?string $hash): string => $hash ?? '(awal rantai)')
            ->all();

        $this->assertSame(
            12,
            count(array_unique($links)),
            'Setiap baris wajib merantai ke ujung yang BERBEDA. `prev_hash` '
            .'ganda berarti dua permintaan membaca ujung rantai yang sama dan '
            .'rantainya bercabang (ADR-005).'
        );

        $verification = app(AuditLogger::class)->verifyChain();

        $this->assertTrue($verification['valid'], 'Rantai hash wajib utuh.');
        $this->assertSame(12, $verification['checked']);
    }

    /**
     * Mekanisme KEP-020, dibuktikan langsung: baris mutex-lah yang menghapus
     * deadlock-nya.
     *
     * Jendela antara pembacaan ujung rantai dan penyisipannya di kode nyata
     * hanya beberapa mikrodetik, sehingga deadlock-nya jarang — uji beban
     * menangkapnya SEKALI dalam ± 44 menit. Uji yang menunggu kebetulan itu
     * tidak akan pernah berguna di CI. Karena itu uji ini memperlebar
     * jendelanya dengan sengaja dan memakai SQL langsung, bukan AuditLogger:
     * yang hendak dibuktikan adalah sifat penguncian InnoDB-nya, dan di situ
     * lapisan di atasnya hanya menyamarkan.
     *
     * Arah yang DITEGASKAN keras hanyalah satu: pola yang mengambil baris
     * mutex lebih dulu tidak boleh pernah gagal. Arah sebaliknya — bahwa pola
     * tanpa mutex memang deadlock — adalah premis KEP-020, dan ia hanya
     * dilaporkan: bila MySQL suatu hari tidak lagi melakukannya, uji ini
     * melewati dirinya sendiri dengan keterangan, bukan gagal. Uji tidak
     * boleh rusak karena basis datanya menjadi lebih baik.
     */
    #[Test]
    #[TestDox('Baris mutex menghapus deadlock 1213 pada perangkaian bersamaan')]
    public function baris_mutex_menghapus_deadlock(): void
    {
        $withoutMutex = $this->runInParallel(workers: 6, scenario: 'tanpa-mutex');
        $deadlocks = count(array_filter(
            $withoutMutex,
            static fn (string $r): bool => str_contains($r, '1213'),
        ));

        DB::table('audit_logs')->truncate();

        $withMutex = $this->runInParallel(workers: 6, scenario: 'dengan-mutex');

        $this->assertSame(
            [],
            array_filter($withMutex, static fn (string $r): bool => $r !== 'ok'),
            'Perangkaian yang mengambil baris mutex lebih dulu tidak boleh '
            .'pernah deadlock. Hasil: '.implode(' | ', $withMutex)
        );

        if ($deadlocks === 0) {
            $this->markTestSkipped(
                'Premis KEP-020 tidak lagi terulang di MySQL ini: pola tanpa '
                .'baris mutex pun tidak deadlock. Baris mutex-nya menjadi '
                .'penjagaan yang mungkin tidak lagi diperlukan — tinjau '
                .'KEP-020 sebelum menghapusnya.'
            );
        }

        $this->assertGreaterThan(
            0,
            $deadlocks,
            'Premis KEP-020: pola lama memang menghasilkan galat 1213.'
        );
    }

    /* ===================== Pembantu ===================== */

    /**
     * Jalankan `$workers` proses bersamaan, masing-masing satu perangkaian.
     *
     * @return array<int, string> 'ok' atau pesan galatnya, per proses
     */
    private function runInParallel(int $workers, string $scenario): array
    {
        /*
         * Berkas penanda dipakai sebagai gerbang start — tanpanya proses yang
         * lebih dulu siap sudah selesai sebelum proses terakhir lahir, dan
         * uji ini kembali menjadi uji berurutan yang menipu.
         */
        $gate = tempnam(sys_get_temp_dir(), 'sid-audit-gate-');
        $resultDir = sys_get_temp_dir().'/sid-audit-'.bin2hex(random_bytes(6));
        mkdir($resultDir);

        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Gagal membuat proses anak untuk uji konkurensi.');
            }

            if ($pid === 0) {
                $this->child($scenario, $i, $gate, $resultDir.'/'.$i);
            }

            $pids[] = $pid;
        }

        usleep(300_000);
        unlink($gate);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        foreach (glob($resultDir.'/*') ?: [] as $file) {
            $results[] = trim((string) file_get_contents($file));
            unlink($file);
        }

        rmdir($resultDir);

        return $results;
    }

    /**
     * Badan proses anak. TIDAK PERNAH kembali — selalu exit().
     *
     * Proses anak mewarisi koneksi PDO induk; ia WAJIB memutusnya lebih dulu
     * agar mendapat koneksinya sendiri. Dua proses yang berbagi satu socket
     * MySQL akan saling merusak protokolnya, dan galatnya menyesatkan.
     */
    private function child(string $scenario, int $index, string $gate, string $outFile): never
    {
        try {
            DB::purge();

            while (file_exists($gate)) {
                usleep(2_000);
            }

            DB::transaction(function () use ($scenario, $index): void {
                if ($scenario === 'logger') {
                    app(AuditLogger::class)->log('uji.bersamaan.'.$index, changes: [
                        'status' => ['old' => 'aktif', 'new' => 'pindah'],
                    ]);

                    return;
                }

                if ($scenario === 'dengan-mutex') {
                    DB::table('audit_chain_lock')->where('id', 1)->lockForUpdate()->first();
                }

                DB::table('audit_logs')->orderByDesc('id')
                    ->lockForUpdate()->first(['id', 'row_hash']);

                /*
                 * Jendela yang di kode nyata hanya beberapa mikrodetik,
                 * diperlebar agar gap lock kedua proses benar-benar tumpang
                 * tindih. Ini satu-satunya hal yang membuat uji ini
                 * deterministik.
                 */
                usleep(120_000);

                DB::table('audit_logs')->insert([
                    'action' => 'uji.mekanisme.'.$index,
                    'row_hash' => str_repeat('0', 64),
                    'hash_version' => 1,
                    'created_at' => now(),
                ]);
            });

            file_put_contents($outFile, 'ok');
        } catch (Throwable $e) {
            file_put_contents($outFile, 'GALAT: '.$e->getMessage());
        }

        // exit(), bukan return: proses anak tidak boleh melanjutkan PHPUnit.
        exit(0);
    }
}
