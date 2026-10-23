<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Complaint;
use App\Models\ResidentMutation;
use App\Repositories\ComplaintRepository;
use App\Repositories\LetterRequestRepository;
use App\Repositories\ResidentRepository;
use App\Support\ReferenceData;
use App\Support\VillageTime;
use Illuminate\Support\Carbon;

/**
 * Penyusun laporan — FR-LAP-03, FR-LAP-04, FR-LAP-05, UC-18.
 *
 * SELURUH angka melewati repository berlingkup, sehingga Kepala Dusun
 * mendapat laporan dusunnya sendiri tanpa satu pun penyaringan tambahan yang
 * ditulis di sini (docs/04 §4.1). Itu juga berarti dua orang dapat mencetak
 * "Rekap Penduduk" pada hari yang sama dan memperoleh angka berbeda — dan
 * itu BENAR. Karena itu setiap laporan membawa keterangan lingkupnya di
 * kepala halaman; laporan tanpa keterangan lingkup akan dibandingkan sebagai
 * dua versi dari angka yang sama, lalu salah satunya dianggap salah.
 */
final class ReportService
{
    public function __construct(
        private readonly ResidentRepository $residents,
        private readonly LetterRequestRepository $requests,
        private readonly ComplaintRepository $complaints,
    ) {}

    /**
     * Rekap penduduk per wilayah & kategori — FR-LAP-03.
     *
     * @return array<string, mixed>
     */
    public function population(): array
    {
        $labels = ReferenceData::labels();

        return [
            'total' => $this->residents->countActive(),
            'per_wilayah' => $this->residents->recapByRegion(),
            'per_kategori' => [
                'jenis_kelamin' => $this->labelled(
                    $this->residents->recapByCategory('gender'),
                    ['L' => 'Laki-laki', 'P' => 'Perempuan'],
                ),
                'agama' => $this->labelled(
                    $this->residents->recapByCategory('religion_code'),
                    $labels['religions'],
                ),
                'pendidikan' => $this->labelled(
                    $this->residents->recapByCategory('education_code'),
                    $labels['educations'],
                ),
                'pekerjaan' => $this->labelled(
                    $this->residents->recapByCategory('occupation_code'),
                    $labels['occupations'],
                ),
                'status_perkawinan' => $this->labelled(
                    $this->residents->recapByCategory('marital_status_code'),
                    $labels['marital_statuses'],
                ),
            ],
        ];
    }

    /**
     * Rekap mutasi penduduk per periode — FR-LAP-04.
     *
     * `resident_mutations` BUKAN tabel dijaga, tetapi ia menyimpan
     * `resident_id` dan karena itu HARUS mengikuti pembatasan wilayah yang
     * sama: Kepala Dusun yang melihat seluruh mutasi desa akan mengetahui
     * siapa saja yang meninggal dan pindah di dusun lain. Penyaringannya
     * memakai subkueri ResidentRepository::visibleIdsQuery(), sehingga
     * aturannya hanya ada di SATU tempat.
     *
     * @return array<string, mixed>
     */
    public function mutations(Carbon $from, Carbon $to): array
    {
        $perJenis = ResidentMutation::query()
            ->whereIn('resident_id', $this->residents->visibleIdsQuery())
            ->whereBetween('mutation_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('type, COUNT(*) AS jumlah')
            ->groupBy('type')
            ->pluck('jumlah', 'type')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $jenis = [
            ResidentMutation::TYPE_BIRTH => 'Lahir',
            ResidentMutation::TYPE_DEATH => 'Meninggal',
            ResidentMutation::TYPE_MOVE_IN => 'Pindah Datang',
            ResidentMutation::TYPE_MOVE_OUT => 'Pindah Keluar',
            ResidentMutation::TYPE_DATA_CHANGE => 'Perubahan Data',
        ];

        $baris = [];

        foreach ($jenis as $kode => $label) {
            /*
             * SELURUH jenis ditampilkan, termasuk yang nol.
             *
             * Baris yang hilang karena tidak ada datanya membuat pembaca
             * laporan harus mengingat sendiri ada berapa jenis mutasi — dan
             * "tidak ada kematian bulan ini" adalah keterangan yang berbeda
             * dari "kolom kematian tidak dilaporkan".
             */
            $baris[] = [
                'kode' => $kode,
                'label' => $label,
                'jumlah' => $perJenis[$kode] ?? 0,
            ];
        }

        return [
            'dari' => $from->toDateString(),
            'sampai' => $to->toDateString(),
            'total' => array_sum(array_column($baris, 'jumlah')),
            'per_jenis' => $baris,
        ];
    }

    /**
     * Rekap layanan surat — FR-LAP-05.
     *
     * @return array<string, mixed>
     */
    public function services(Carbon $from, Carbon $to): array
    {
        return [
            'dari' => $from->toDateString(),
            'sampai' => $to->toDateString(),
            'per_jenis' => $this->requests->serviceRecap($from, $to),
            'sla' => $this->requests->slaPerformance(),
            'tren' => $this->requests->monthlyTrend(),
        ];
    }

    /**
     * Rekap pengaduan — BR-30.
     *
     * Tidak disebut eksplisit pada FR-LAP-03..07, tetapi matriks RBAC
     * docs/03 §4.9 memberi baris "Laporan & ekspor" hak ekspor kepada peran
     * yang sama yang mengawasi pengaduan, dan pimpinan yang harus menjawab
     * "berapa aduan warga tahun ini dan berapa yang selesai" tidak punya
     * tempat lain untuk mengambilnya selain menghitung manual dari antrean.
     *
     * @return array<string, mixed>
     */
    public function complaints(): array
    {
        $perStatus = $this->complaints->countByStatus();
        $labels = Complaint::statusLabels();

        $baris = [];

        foreach ($labels as $kode => $label) {
            $baris[] = [
                'kode' => $kode,
                'label' => $label,
                'jumlah' => $perStatus[$kode] ?? 0,
            ];
        }

        return [
            'total' => array_sum(array_column($baris, 'jumlah')),
            'terbuka' => $this->complaints->countOpen(),
            'terlambat' => $this->complaints->countOverdue(),
            'per_status' => $baris,
        ];
    }

    /**
     * Periode bawaan: tahun berjalan pada KALENDER DESA.
     *
     * Bukan 12 bulan terakhir: laporan ke kecamatan dan DPMD selalu diminta
     * per tahun takwim, dan bawaan yang bergeser setiap hari membuat dua
     * cetakan dari minggu yang berbeda tidak dapat dibandingkan.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function defaultPeriod(): array
    {
        $now = VillageTime::local(now());

        return [$now->copy()->startOfYear(), $now->copy()->endOfDay()];
    }

    /**
     * Kode mentah menjadi label terbaca, dengan jumlah ikut terbawa.
     *
     * Kode yang tidak ada di tabel referensi TIDAK dibuang melainkan
     * ditampilkan apa adanya: baris yang hilang membuat jumlah tidak cocok
     * dengan total, dan kode asing di laporan adalah petunjuk nyata bahwa ada
     * data impor yang perlu dibersihkan.
     *
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $labels
     * @return list<array{kode: string, label: string, jumlah: int}>
     */
    private function labelled(array $counts, array $labels): array
    {
        $rows = [];

        foreach ($counts as $kode => $jumlah) {
            $rows[] = [
                'kode' => (string) $kode,
                'label' => $kode === ''
                    ? 'Tidak diisi'
                    : ($labels[$kode] ?? (string) $kode),
                'jumlah' => $jumlah,
            ];
        }

        return $rows;
    }
}
