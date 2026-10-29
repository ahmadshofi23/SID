<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Support\NameNormalizer;
use App\Support\Pii\Nik;
use App\Support\Pii\PiiCipher;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Penyemai VOLUME — bukan data contoh, tetapi tumpukan data untuk uji beban
 * & volume (docs/09-testing-qa.md §4.5, NFR-PERF-07).
 *
 * MENGAPA INI SEEDER DAN BUKAN ISI PERINTAHNYA SENDIRI
 * ----------------------------------------------------
 * Penyemai ini menulis langsung ke `families`, `residents`, `letter_requests`,
 * dan `complaints` dengan sisipan massal — persis yang dilarang
 * tests/Architecture/RepositoryBoundaryTest.php di app/Console/Commands.
 * Larangan itu TIDAK dilanggar di sini dengan menambah pengecualian, karena
 * alasannya memang tidak berlaku: batas lapis repository ada supaya kueri di
 * jalur permintaan pengguna tidak dapat lupa menyaring wilayah/keluarga
 * (KEP-002). Penyemai tidak punya pengguna, tidak punya lingkup akses, dan
 * tidak pernah mengembalikan baris ke siapa pun — sama seperti
 * DemoDataSeeder. Yang berjalan di app/Console/Commands hanyalah penjaga
 * lingkungan dan pemilih basis data (App\Console\Commands\SeedVolume).
 *
 * MENGAPA SISIPAN MASSAL DAN BUKAN ELOQUENT
 * -----------------------------------------
 * 20.000 penduduk lewat Resident::create() berarti 20.000 INSERT, 20.000
 * pembuatan UUID lewat model, dan 20.000 putaran event — di mesin ini
 * ± 6 menit. Dengan sisipan 500 baris sekali jalan, pekerjaan yang sama
 * selesai dalam hitungan detik. Itu bukan sekadar kenyamanan: penyemai yang
 * lambat tidak akan pernah dijalankan ulang, dan uji volume yang tidak pernah
 * dijalankan ulang berhenti menjadi uji.
 *
 * YANG TETAP DIHITUNG APLIKASI, BUKAN DIPALSUKAN
 * ----------------------------------------------
 * 1. `nik_enc` / `nik_hash` / `nik_masked` tetap lewat PiiCipher (ADR-004).
 *    Tanpa itu pencarian NIK tidak akan menemukan apa pun, sehingga angka
 *    "pencarian NIK ≤ x ms" yang terukur tidak berarti apa-apa.
 * 2. `full_name_norm` tetap lewat NameNormalizer. Inilah kolom yang diukur
 *    kriteria `LIKE '%…%'` ≤ 200 ms.
 * 3. Rantai hash audit log tetap DIHITUNG BERURUTAN, sehingga
 *    `php artisan sid:verify-audit-chain` masih lulus di atas 1,5 juta baris.
 *    Mengisinya dengan hash acak lebih cepat, tetapi kemudian perintah
 *    verifikasi itu sendiri tidak dapat diuji pada volume sebenarnya —
 *    padahal ia justru perintah yang harus memindai seluruh tabel.
 */
final class VolumeSeeder extends Seeder
{
    /**
     * Baris per sisipan.
     *
     * 500 adalah kompromi: lebih besar membuat satu pernyataan melewati
     * `max_allowed_packet` bawaan MySQL pada tabel penduduk (± 40 kolom,
     * sebagian terenkripsi), lebih kecil membuat biaya bolak-balik jaringan
     * mendominasi.
     */
    private const CHUNK = 500;

    /** Rata-rata anggota per KK — angka BPS untuk desa Jawa Barat dibulatkan. */
    private const MEMBERS_PER_FAMILY = 3.2;

    private PiiCipher $cipher;

    /**
     * Penghitung NIK per (tanggal lahir, jenis kelamin) — lihat nik().
     *
     * @var array<string, int>
     */
    private array $nikCounters = [];

    /** @var list<string> */
    private array $rtIds = [];

    /** @var list<string> */
    private array $firstNames = [
        'Ahmad', 'Siti', 'Budi', 'Dewi', 'Agus', 'Rina', 'Joko', 'Wati',
        'Hendra', 'Nur', 'Bambang', 'Yuli', 'Slamet', 'Endang', 'Dedi',
        'Tuti', 'Eko', 'Lilis', 'Rudi', 'Sri', 'Asep', 'Iis', 'Yusuf',
        'Umi', 'Herman', 'Ratna', 'Sutrisno', 'Mega', 'Fajar', 'Indah',
    ];

    /** @var list<string> */
    private array $lastNames = [
        'Hidayat', 'Wijaya', 'Santoso', 'Lestari', 'Pratama', 'Rahayu',
        'Saputra', 'Nugroho', 'Kusuma', 'Maulana', 'Firdaus', 'Permana',
        'Suryana', 'Gunawan', 'Halim', 'Yulianto', 'Setiawan', 'Puspita',
        'Anggraini', 'Ramadhan', 'Sudirman', 'Mulyana', 'Hartono', 'Safitri',
    ];

    /** @var list<string> */
    private array $religions = ['islam', 'islam', 'islam', 'islam', 'kristen', 'katolik', 'hindu'];

    /** @var list<string> */
    private array $educations = [
        'tidak_belum_sekolah', 'belum_tamat_sd', 'tamat_sd', 'tamat_sd',
        'sltp', 'slta', 'slta', 'd3', 'd4_s1', 's2',
    ];

    /** @var list<string> */
    private array $occupations = [
        'belum_tidak_bekerja', 'mengurus_rumah_tangga', 'pelajar_mahasiswa',
        'petani', 'petani', 'buruh_tani', 'buruh_harian', 'karyawan_swasta',
        'wiraswasta', 'pedagang', 'guru', 'pns', 'pensiunan', 'sopir',
    ];

    /**
     * @param  int  $residents  jumlah penduduk yang disemai
     * @param  int  $auditRows  jumlah baris audit log
     * @param  int  $letterRequests  pengajuan surat (mengisi antrean surat)
     * @param  int  $complaints  pengaduan (mengisi antrean pengaduan)
     */
    public function run(
        int $residents = 20_000,
        int $auditRows = 1_500_000,
        int $letterRequests = 2_000,
        int $complaints = 2_000,
    ): void {
        $this->cipher = app(PiiCipher::class);

        $this->rtIds = DB::table('regions')->where('level', 'rt')->pluck('id')->all();

        if ($this->rtIds === []) {
            throw new RuntimeException(
                'Tidak ada wilayah tingkat RT. Jalankan `php artisan db:seed` '
                .'lebih dulu — penyemai volume menempelkan penduduk pada '
                .'hierarki wilayah yang sudah ada, bukan membuatnya sendiri.'
            );
        }

        if ($residents > 0) {
            $this->seedResidents($residents);
        }

        if ($letterRequests > 0) {
            $this->seedLetterRequests($letterRequests);
        }

        if ($complaints > 0) {
            $this->seedComplaints($complaints);
        }

        if ($auditRows > 0) {
            $this->seedAuditLogs($auditRows);
        }
    }

    /* =================== Penduduk & keluarga =================== */

    /*
     * KELUARAN LAYAR LEWAT PROPERTI SENDIRI, bukan lewat `$this->command`
     * milik Seeder.
     *
     * Properti `Seeder::$command` tidak ber-tipe dan tidak nullable menurut
     * PHPDoc-nya, padahal ia BELUM TERISI bila penyemai dibuat langsung
     * dengan `new VolumeSeeder` — yang justru cara uji memakainya
     * (tests/Feature/Volume/VolumeSeederTest.php). Bergantung padanya
     * memaksa memilih antara galat analisis statis dan galat saat jalan.
     * Dengan keluaran sebagai kebergantungan yang diserahkan terang-terangan,
     * keduanya hilang: tanpa keluaran, penyemai diam.
     */
    private ?OutputStyle $output = null;

    public function withOutput(OutputStyle $output): self
    {
        $this->output = $output;

        return $this;
    }

    private function tulisBaris(string $message): void
    {
        $this->output?->writeln($message);
    }

    private function mulaiProgres(int $max): void
    {
        $this->output?->progressStart($max);
    }

    private function majuProgres(int $step): void
    {
        $this->output?->progressAdvance($step);
    }

    private function selesaiProgres(): void
    {
        $this->output?->progressFinish();
    }

    private function seedResidents(int $target): void
    {
        /*
         * Nomor awal diambil dari jumlah baris yang sudah ada, bukan dari 1.
         * Penyemai ini boleh dijalankan dua kali (mis. menambah 20.000 lagi
         * untuk uji tekanan), dan NIK sintetis yang berulang akan ditolak
         * indeks `uq_residents_nik_active` di tengah jalan — setelah separuh
         * data masuk, yang jauh lebih menyusahkan daripada ditolak di awal.
         */
        $offset = (int) DB::table('residents')->count();
        $familyCount = (int) ceil($target / self::MEMBERS_PER_FAMILY);
        $familyOffset = (int) DB::table('families')->count();

        $this->mulaiProgres($target);

        $familyBuffer = [];
        $residentBuffer = [];
        $created = 0;
        $familyIndex = 0;

        while ($created < $target) {
            $familyIndex++;
            $rtId = $this->rtIds[($familyIndex - 1) % count($this->rtIds)];
            $familyId = (string) Str::uuid7();
            $kkNumber = $this->kkNumber($familyOffset + $familyIndex);
            $address = sprintf('Jl. %s No. %d', $this->lastNames[$familyIndex % count($this->lastNames)], ($familyIndex % 180) + 1);

            $familyBuffer[] = [
                'id' => $familyId,
                'kk_number_enc' => $this->cipher->encrypt($kkNumber),
                'kk_number_hash' => $this->cipher->hash($kkNumber),
                'kk_number_masked' => Nik::mask($kkNumber),
                'region_id' => $rtId,
                'address' => $address,
                'welfare_status_code' => 'menengah',
                'house_ownership_code' => 'milik_sendiri',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Besar KK berkisar 1–5 agar sebaran umur & relasi tetap masuk akal.
            $members = min((int) (1 + ($familyIndex * 7) % 5), $target - $created);
            $surname = $this->lastNames[crc32('sid-kk-'.$familyIndex) % count($this->lastNames)];

            for ($m = 0; $m < $members; $m++) {
                $created++;
                $residentBuffer[] = $this->residentRow(
                    $offset + $created, $familyId, $rtId, $address, $surname, $m, $familyIndex
                );
            }

            if (count($familyBuffer) >= self::CHUNK) {
                $this->flushFamilies($familyBuffer);
                $this->flushResidents($residentBuffer);
                $this->majuProgres(count($residentBuffer));
                $familyBuffer = [];
                $residentBuffer = [];
            }
        }

        $this->flushFamilies($familyBuffer);
        $this->flushResidents($residentBuffer);
        $this->selesaiProgres();
        $this->tulisBaris(sprintf(
            '  %s penduduk dalam %s KK disemai (perkiraan %d KK).',
            number_format($created, 0, ',', '.'),
            number_format($familyIndex, 0, ',', '.'),
            $familyCount,
        ));
    }

    /**
     * Satu baris penduduk.
     *
     * @return array<string, mixed>
     */
    private function residentRow(
        int $seq,
        string $familyId,
        string $rtId,
        string $address,
        string $surname,
        int $memberIndex,
        int $familyIndex,
    ): array {
        $isHead = $memberIndex === 0;

        /*
         * Nama disusun dari crc32, BUKAN dari `$seq % jumlah`.
         *
         * Versi pertama penyemai ini memakai `$seq * 13 % 30` untuk nama depan
         * dan `$familyIndex * 3 % 24` untuk nama belakang. Panjang siklus
         * keduanya beresonansi dengan besar KK, dan hasilnya 20.000 penduduk
         * hanya memakai 121 nama yang berbeda — satu nama dipakai 667 orang.
         * Angka `LIKE '%…%'` yang terukur di atas tumpukan seperti itu tidak
         * menggambarkan apa pun: setiap pencarian mengenai ratusan baris yang
         * identik, dan selektivitas yang dihadapi petugas di lapangan hilang.
         * Ditemukan ketika penyiap data k6 hanya berhasil menyusun SATU kata
         * pencarian dari empat puluh nama pertama.
         */
        $mix = crc32('sid-volume-'.$seq);
        $first = $this->firstNames[$mix % count($this->firstNames)];
        $middle = $this->firstNames[intdiv($mix, 131) % count($this->firstNames)];

        /*
         * Tiga pola nama, dan ketiganya ada di desa mana pun: satu kata
         * (satu dari dua belas), dua kata, dan tiga kata. Panjang nama
         * menentukan selektivitas `LIKE '%…%'`; tumpukan yang seluruhnya
         * berpola "Depan Belakang" membuat angka pencarian terukur lebih
         * bagus daripada kenyataan.
         */
        $name = match (true) {
            // Nama > 100 karakter dan nama ber-apostrof — dua kasus tepi
            // docs/09 §3 yang keduanya menyentuh kolom pencarian:
            // NameNormalizer membuang apostrofnya (Ma'ruf -> maruf), dan nama
            // panjang adalah satu-satunya yang mendekati batas 150 karakter
            // kolom `full_name_norm`.
            $mix % 400 === 0 => $this->namaPanjang($first, $middle, $surname),
            $mix % 40 === 0 => $first." Ma'ruf ".$surname,
            $mix % 12 === 0 => $first,
            $mix % 3 === 0 => $first.' '.$middle.' '.$surname,
            default => $first.' '.$surname,
        };

        $gender = $isHead ? 'L' : ($seq % 2 === 0 ? 'P' : 'L');

        /*
         * Tanggal lahir dibentang lebar DAN menyentuh dua ujungnya.
         *
         * Satu dari 700 penduduk berusia di atas seratus tahun dan satu dari
         * 700 berumur beberapa hari — dua kasus tepi yang diminta docs/09 §3
         * dan dua-duanya mengubah hasil yang diukur: piramida umur dan
         * penyaringan `age_min/age_max` membagi penduduk ke dalam kelompok
         * lima tahunan, dan tumpukan yang seluruhnya berusia 30–70 tahun
         * membuat kelompok ujungnya kosong sehingga biaya mengelompokkannya
         * tidak pernah terukur.
         */
        $birthDate = match ($seq % 700) {
            0 => sprintf('%04d-%02d-%02d', 1921 + ($seq % 4), ($seq % 12) + 1, ($seq % 28) + 1),
            1 => now()->subDays($seq % 20)->toDateString(),
            default => sprintf(
                '%04d-%02d-%02d',
                $isHead ? 1955 + ($seq % 35) : 1975 + ($seq % 48),
                ($seq % 12) + 1,
                ($seq % 28) + 1,
            ),
        };

        // NIK dibangkitkan SETELAH tanggal lahir & jenis kelamin diketahui:
        // keduanya adalah bagian dari nomornya (docs/09 §3, lihat nik()).
        $nik = $this->nik($birthDate, $gender);

        $relation = match (true) {
            $isHead => 'kepala_keluarga',
            $memberIndex === 1 => $gender === 'P' ? 'istri' : 'suami',
            $memberIndex >= 4 => 'orang_tua',
            default => 'anak',
        };

        /*
         * Sebagian kecil penduduk TIDAK berstatus aktif.
         *
         * Statistik desa dan Buku Induk menyaring RULE-03; tumpukan yang
         * seluruhnya aktif membuat penyaringan itu gratis dan menyembunyikan
         * biaya sebenarnya pada indeks `idx_residents_status`.
         */
        $status = match ($seq % 50) {
            0 => 'meninggal',
            17 => 'pindah_keluar',
            default => 'aktif',
        };

        return [
            'id' => (string) Str::uuid7(),
            'family_id' => $familyId,
            'region_id' => $rtId,
            'nik_enc' => $this->cipher->encrypt($nik),
            'nik_hash' => $this->cipher->hash($nik),
            'nik_masked' => Nik::mask($nik),
            'full_name' => $name,
            'full_name_norm' => NameNormalizer::normalize($name),
            'birth_place' => 'Sukamaju',
            'birth_date' => $birthDate,
            'gender' => $gender,
            'religion_code' => $this->religions[$seq % count($this->religions)],
            'marital_status_code' => $isHead ? 'kawin_tercatat' : 'belum_kawin',
            'family_relation_code' => $relation,
            'education_code' => $this->educations[$seq % count($this->educations)],
            'occupation_code' => $this->occupations[$seq % count($this->occupations)],
            'citizenship' => 'WNI',
            'address' => $address,
            'status' => $status,
            'status_changed_at' => $status === 'aktif' ? null : now(),
            'is_poor' => $seq % 9 === 0,
            'created_at' => now(),
            'updated_at' => now(),
            'notes' => null,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function flushFamilies(array $rows): void
    {
        if ($rows !== []) {
            DB::table('families')->insert($rows);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function flushResidents(array $rows): void
    {
        if ($rows !== []) {
            DB::table('residents')->insert($rows);
        }
    }

    /* =================== Antrean surat =================== */

    private function seedLetterRequests(int $target): void
    {
        $typeIds = DB::table('letter_types')->pluck('id')->all();
        $residentIds = DB::table('residents')->limit($target)->pluck('id')->all();

        if ($typeIds === [] || $residentIds === []) {
            return;
        }

        $statuses = ['diajukan', 'diajukan', 'diverifikasi', 'diterbitkan', 'diterbitkan', 'ditolak'];
        $offset = (int) DB::table('letter_requests')->count();
        $buffer = [];

        for ($i = 1; $i <= $target; $i++) {
            $status = $statuses[$i % count($statuses)];
            $submittedAt = now()->subDays($i % 400)->subMinutes($i % 1440);

            $buffer[] = [
                'id' => (string) Str::uuid7(),
                'letter_type_id' => $typeIds[$i % count($typeIds)],
                'ticket_number' => sprintf('VOL-SRT-%06d', $offset + $i),
                'resident_id' => $residentIds[$i % count($residentIds)],
                'requested_by' => null,
                'channel' => $i % 3 === 0 ? 'walk_in' : 'online',
                'purpose' => 'Keperluan administrasi (data uji beban).',
                'form_data' => '{}',
                'status' => $status,
                'current_step' => $status === 'diajukan' ? 0 : 1,
                'submitted_at' => $submittedAt,
                'due_at' => $submittedAt->copy()->addDays(3),
                'completed_at' => $status === 'diterbitkan' ? $submittedAt->copy()->addDays(1) : null,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ];

            if (count($buffer) >= self::CHUNK) {
                DB::table('letter_requests')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('letter_requests')->insert($buffer);
        }

        $this->tulisBaris(sprintf(
            '  %s pengajuan surat disemai.', number_format($target, 0, ',', '.')
        ));
    }

    /* =================== Antrean pengaduan =================== */

    private function seedComplaints(int $target): void
    {
        $categories = ['infrastruktur', 'pelayanan', 'bantuan_sosial', 'lingkungan', 'keamanan', 'kesehatan'];
        $statuses = ['baru', 'baru', 'diterima', 'diproses', 'selesai', 'selesai', 'ditolak'];
        $offset = (int) DB::table('complaints')->count();
        $buffer = [];

        for ($i = 1; $i <= $target; $i++) {
            $status = $statuses[$i % count($statuses)];
            $createdAt = now()->subDays($i % 400)->subMinutes($i % 1440);
            $anonymous = $i % 4 === 0;

            $buffer[] = [
                'id' => (string) Str::uuid7(),
                'ticket_number' => sprintf('VOL-PGD-%06d', $offset + $i),
                'tracking_code' => strtoupper(Str::random(12)),
                'category_code' => $categories[$i % count($categories)],
                'title' => 'Pengaduan uji beban nomor '.($offset + $i),
                'body' => 'Isi pengaduan sintetis untuk uji beban & volume (docs/09 §4.5).',
                'is_anonymous' => $anonymous,
                // RULE-09: pengaduan anonim TIDAK boleh menyimpan identitas.
                'reporter_user_id' => null,
                'reporter_name' => $anonymous ? null : 'Pelapor Uji '.$i,
                'reporter_phone' => $anonymous ? null : '08120000'.sprintf('%04d', $i % 10000),
                'region_id' => $this->rtIds[$i % count($this->rtIds)],
                'location_text' => 'Lokasi uji',
                'status' => $status,
                'priority' => $i % 11 === 0 ? 'tinggi' : 'normal',
                'triage_due_at' => $createdAt->copy()->addDays(2),
                'due_at' => $status === 'baru' ? null : $createdAt->copy()->addDays(14),
                'resolved_at' => in_array($status, ['selesai', 'ditolak'], true)
                    ? $createdAt->copy()->addDays(5)
                    : null,
                /*
                 * Pengaduan DITOLAK wajib beralasan — CHECK
                 * `chk_complaints_tolak_beralasan`. Basis data menolak
                 * penolakan tanpa alasan, dan itu benar: warga yang
                 * pengaduannya ditolak berhak tahu mengapa.
                 */
                'resolution' => match ($status) {
                    'selesai' => 'Sudah ditindaklanjuti.',
                    'ditolak' => 'Di luar kewenangan desa (data uji beban).',
                    default => null,
                },
                'is_published' => $status === 'selesai' && $i % 5 === 0,
                'submitted_ip_hash' => $this->cipher->hash('10.0.0.'.($i % 254)),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if (count($buffer) >= self::CHUNK) {
                DB::table('complaints')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('complaints')->insert($buffer);
        }

        $this->tulisBaris(sprintf(
            '  %s pengaduan disemai.', number_format($target, 0, ',', '.')
        ));
    }

    /* =================== Audit log =================== */

    /**
     * 1,5 juta baris audit log DENGAN rantai hash yang utuh.
     *
     * Rantainya dihitung di PHP, berurutan, tanpa menyentuh AuditLogger:
     * AuditLogger membuka satu transaksi dan satu `SELECT ... FOR UPDATE` per
     * baris (yang memang benar untuk jalur permintaan, karena dua permintaan
     * bersamaan tidak boleh mencabangkan rantai). Pada 1,5 juta baris itu
     * berarti 1,5 juta transaksi — berjam-jam. Di sini tidak ada permintaan
     * bersamaan sama sekali, sehingga penguncian itu tidak melindungi apa pun
     * dan dapat ditinggalkan tanpa mengubah hasilnya.
     *
     * Hash dihitung lewat App\Models\AuditLog::hashFromRow(), yang menerima
     * array mentah dan karena itu tidak memaksa penyemai ini membuat 1,5 juta
     * instance Eloquent — biaya terbesar di seluruh penyemai bila model yang
     * dipakai. Sebelum KEP-020 metode itu belum ada, sehingga penyemai ini
     * MENYALIN rumusnya; salinan itulah yang membuat rumus penyemai dan rumus
     * model menyimpang tanpa ada yang menyadarinya sampai uji beban Sprint 7.
     * Satu rumus di satu tempat adalah inti perbaikannya: tidak ada lagi
     * salinan yang dapat tertinggal.
     */
    private function seedAuditLogs(int $target): void
    {
        $actions = [
            'auth.login', 'auth.logout', 'resident.updated', 'resident.created',
            'letter.issued', 'data.pii_revealed', 'data.exported',
            'complaint.status_changed', 'family.updated', 'auth.login_failed',
        ];

        $actors = DB::table('users')->limit(10)->get(['id', 'name'])->all();

        if ($actors === []) {
            throw new RuntimeException(
                'Tidak ada pengguna. Jalankan `php artisan db:seed` lebih dulu.'
            );
        }

        /** @var object{id: int, row_hash: string}|null $last */
        $last = DB::table('audit_logs')->orderByDesc('id')->first(['id', 'row_hash']);
        $prevHash = $last?->row_hash;

        /*
         * Rentang waktu dibentang 24 bulan ke belakang.
         *
         * Semua baris bertanggal hari ini akan membuat setiap laporan
         * ber-rentang tanggal hanya menyentuh satu titik indeks
         * `idx_audit_created` — justru kasus yang paling cepat, dan karena itu
         * paling tidak berguna untuk diukur.
         */
        $start = now()->subMonths(24);
        $spanSeconds = (int) $start->diffInSeconds(now());

        $this->mulaiProgres($target);

        $buffer = [];

        for ($i = 1; $i <= $target; $i++) {
            $actor = $actors[$i % count($actors)];
            $createdAt = $start->copy()->addSeconds((int) ($spanSeconds * $i / $target));
            $action = $actions[$i % count($actions)];
            /*
             * DUA kunci di dalam `context`, dan urutannya sengaja tidak
             * menurut abjad maupun panjang kunci.
             *
             * Sebelum KEP-020 di sini hanya ada satu kunci, justru untuk
             * menghindari cacat yang kini sudah ditutup: kolom `context`
             * bertipe JSON, dan MySQL MENATA ULANG kunci objek JSON saat
             * menyimpannya (urut panjang kunci, lalu bytewise). Rumus lama
             * meng-encode menurut urutan tulis, sehingga baris berkunci banyak
             * dibaca kembali dengan urutan yang berbeda dan `sid:verify-audit-chain`
             * melaporkan insiden Sev-1 yang tidak pernah terjadi — ditemukan
             * tepat begitu, pada baris pertama hasil penyemai ini.
             *
             * Rumus baru mengurutkan kunci sebelum meng-encode, jadi dua kunci
             * di sini bukan lagi risiko melainkan PEMBUKTIAN pada 1,5 juta
             * baris bahwa penataan ulang MySQL sudah tidak berpengaruh.
             * `sumber` ditulis lebih dulu padahal MySQL akan mengembalikannya
             * SETELAH `urut` (enam huruf melawan empat), jadi setiap baris
             * penyemai ini dibaca kembali dengan urutan kunci yang berbeda
             * dari urutan tulisnya — bila kanonikalisasi hilang lagi,
             * penyemai inilah yang pertama berteriak, pada baris pertama.
             */
            $context = ['sumber' => 'penyemai-volume', 'urut' => $i];

            $row = [
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'actor_roles' => 'operator_desa',
                'action' => $action,
                'entity_type' => null,
                'entity_id' => null,
                /*
                 * `null`, dan kini itu benar-benar masuk ke hash (KEP-020):
                 * sebelum perbaikan, kolom ini tidak pernah ikut dihitung.
                 * Dibiarkan kosong agar ukuran baris — dan karena itu angka
                 * uji beban di docs/23 §5 — tetap sebanding dengan pengukuran
                 * sebelumnya; perlindungan kolom ini dijaga uji regresinya
                 * sendiri, tests/Feature/AuditLogTest::perubahan_kolom_changes_terdeteksi.
                 */
                'changes' => null,
                'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'ip_address' => '10.0.0.'.($i % 254),
                'user_agent' => 'k6/uji-beban',
                'correlation_id' => null,
                'prev_hash' => $prevHash,
                'hash_version' => AuditLog::HASH_VERSION,
                'created_at' => $createdAt->format('Y-m-d H:i:s.v'),
            ];

            $prevHash = AuditLog::hashFromRow($row, $row['prev_hash']);
            $row['row_hash'] = $prevHash;

            $buffer[] = $row;

            if (count($buffer) >= 2_000) {
                DB::table('audit_logs')->insert($buffer);
                $this->majuProgres(count($buffer));
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('audit_logs')->insert($buffer);
            $this->majuProgres(count($buffer));
        }

        $this->selesaiProgres();
        $this->tulisBaris(sprintf(
            '  %s baris audit log disemai (rantai hash utuh).',
            number_format($target, 0, ',', '.')
        ));
    }

    /**
     * Nama lebih dari 100 karakter tetapi tetap di bawah 150 — kasus tepi
     * docs/09 §3.
     *
     * Disusun dengan lingkaran, bukan `str_repeat(..., 4)`: panjang satu
     * satuan nama di sini berkisar 15–28 karakter, sehingga pengali tetap
     * apa pun akan kadang menghasilkan nama 76 karakter (tidak melewati 100,
     * jadi kasus tepinya tidak pernah muncul) dan kadang 168 karakter (lebih
     * panjang dari kolom `full_name` VARCHAR(150), jadi penyemainya gagal).
     * Keduanya pernah terjadi pada percobaan pertama.
     */
    private function namaPanjang(string $first, string $middle, string $surname): string
    {
        $satuan = $first.' '.$middle.' '.$surname;
        $nama = $satuan;

        while (strlen($nama) < 105) {
            $nama .= ' '.$satuan;
        }

        return substr($nama, 0, 140);
    }

    /**
     * NIK sintetis yang VALID SECARA STRUKTUR — persyaratan docs/09 §3.
     *
     * Pola Dukcapil: PPKKCC DDMMYY NNNN
     *   320101  kode provinsi/kabupaten/kecamatan desa contoh
     *   DDMMYY  tanggal lahir; untuk PEREMPUAN tanggalnya + 40
     *   NNNN    nomor urut penduduk yang lahir pada tanggal itu
     *
     * MENGAPA BERPOLA, PADAHAL NOMOR URUT MURNI LEBIH MUDAH DIJAMIN UNIK
     * -----------------------------------------------------------------
     * docs/09 §3 menuntutnya, dan tuntutan itu punya akibat nyata di uji ini:
     * `Nik::warnings()` memperingatkan bila kode wilayah atau tanggal lahir di
     * dalam NIK tidak cocok dengan kolomnya (lihat App\Support\Pii\Nik). NIK
     * berpola asal-asalan membuat SELURUH 20.000 penduduk membawa peringatan,
     * sehingga layar Anomali Data (`kependudukan/anomali`) yang ikut diukur uji
     * beban memuat 20.000 temuan palsu — halaman yang terukur bukan lagi
     * halaman yang dipakai petugas.
     *
     * Keunikannya dijaga penghitung per (tanggal, jenis kelamin): satu tanggal
     * menampung 10.000 orang, dan 20.000 penduduk tersebar di puluhan ribu
     * tanggal lahir yang berbeda. Penghitung itu HARUS hidup selama satu
     * proses penyemaian — tanpanya dua penduduk yang lahir pada hari yang sama
     * mendapat NIK yang sama dan ditolak `uq_residents_nik_active` di tengah
     * jalan, setelah separuh data masuk.
     *
     * @param  string  $birthDate  Y-m-d
     * @param  string  $gender  L|P
     */
    private function nik(string $birthDate, string $gender): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $birthDate));

        // Perempuan: tanggal + 40 (aturan Dukcapil, bukan kebiasaan lokal).
        $dayCode = $gender === 'P' ? $day + 40 : $day;

        $kunci = sprintf('%02d%02d%02d', $dayCode, $month, $year % 100);
        $urut = ($this->nikCounters[$kunci] = ($this->nikCounters[$kunci] ?? 0) + 1);

        if ($urut > 9999) {
            throw new RuntimeException(
                "Lebih dari 9.999 penduduk lahir pada {$birthDate} — ruang NIK "
                .'untuk satu tanggal habis. Lebarkan sebaran tanggal lahir di '
                .'residentRow(), jangan membuang pola NIK-nya (docs/09 §3).'
            );
        }

        return '320101'.$kunci.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Nomor KK sintetis berpola: PPKKCC + DDMMYY penerbitan + NNNN urut.
     *
     * Tanggal penerbitan diputar pada 28 hari agar satu tanggal tidak perlu
     * menampung lebih dari 10.000 KK; dengan itu penyemai masih benar bila
     * dijalankan beberapa kali untuk uji tekanan.
     */
    private function kkNumber(int $sequence): string
    {
        $day = ($sequence % 28) + 1;
        $urut = intdiv($sequence, 28) % 10_000;

        return '320101'.sprintf('%02d0126', $day).str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }
}
