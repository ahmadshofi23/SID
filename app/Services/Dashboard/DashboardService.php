<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Auth\AccessScope;
use App\Http\Resources\ComplaintPresenter;
use App\Models\Complaint;
use App\Models\LetterRequest;
use App\Models\ResidentMutation;
use App\Repositories\ComplaintRepository;
use App\Repositories\FamilyRepository;
use App\Repositories\LetterRequestRepository;
use App\Repositories\ResidentRepository;
use App\Services\Population\StatisticsService;
use App\Support\VillageTime;

/**
 * Penyusun dashboard — FR-LAP-01, FR-LAP-02, UC-17, docs/07 §5.4.
 *
 * SETIAP BAGIAN DIHITUNG HANYA BILA PENGGUNA BERHAK MELIHATNYA, dan yang
 * tidak berhak mendapat `null` — bukan nol.
 *
 * Perbedaan itu penting dan bukan kerewelan: kartu "Pengaduan terbuka: 0"
 * bagi orang yang memang tidak boleh melihat pengaduan adalah pernyataan yang
 * SALAH, dan di dashboard pimpinan kesalahan seperti itu berakhir sebagai
 * keputusan. Layar menyembunyikan kartunya ketika nilainya null.
 *
 * Seluruh hitungan melewati repository berlingkup, sehingga Kepala Dusun
 * melihat angka dusunnya sendiri tanpa satu pun penyaringan tambahan yang
 * ditulis di sini (docs/04 §4.1).
 */
final class DashboardService
{
    public function __construct(
        private readonly ResidentRepository $residents,
        private readonly FamilyRepository $families,
        private readonly LetterRequestRepository $requests,
        private readonly ComplaintRepository $complaints,
        private readonly StatisticsService $statistics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forScope(AccessScope $scope): array
    {
        return [
            'kependudukan' => $this->population($scope),
            'layanan' => $this->letters($scope),
            'pengaduan' => $this->complaintSummary($scope),
            'tren' => $this->trend($scope),
            'perlu_tindakan' => $this->awaitingMyAction($scope),
            'pengaduan_terbuka' => $this->openComplaints($scope),
            'komposisi' => $this->composition($scope),
        ];
    }

    /** @return array<string, mixed>|null */
    private function population(AccessScope $scope): ?array
    {
        if (! $scope->can('penduduk.lihat')) {
            return null;
        }

        return [
            'penduduk_aktif' => $this->residents->countActive(),
            'penduduk_total' => $this->residents->countAll(),
            'keluarga' => $scope->can('keluarga.lihat') ? $this->families->countAll() : null,
            'perubahan_bulan_ini' => $scope->can('mutasi.lihat')
                ? $this->mutationsThisMonth()
                : null,
        ];
    }

    /**
     * Perubahan penduduk bulan ini — FR-LAP-01.
     *
     * `resident_mutations` BUKAN tabel dijaga (config/sid.php), sehingga ia
     * boleh dibaca langsung dari sini. Yang dikembalikan hanya CACAH, tanpa
     * satu pun identitas, dan itu sebabnya ia tidak menuntut lapis repository
     * berlingkup: angka "17 perubahan bulan ini" tidak memberi tahu siapa pun
     * tentang warga mana.
     *
     * Batas bulannya dihitung pada kalender desa (KEP-010): awal bulan di UTC
     * jatuh pukul 07.00 pagi tanggal 1 di Indonesia, sehingga mutasi yang
     * dicatat subuh tanggal 1 akan terhitung di bulan sebelumnya.
     */
    private function mutationsThisMonth(): int
    {
        $awalBulan = VillageTime::local(now())->startOfMonth()->utc();

        return ResidentMutation::query()
            ->where('created_at', '>=', $awalBulan)
            ->count();
    }

    /** @return array<string, mixed>|null */
    private function letters(AccessScope $scope): ?array
    {
        if (! $scope->can('surat.lihat')) {
            return null;
        }

        $perStatus = $this->requests->countByStatus();

        return [
            'terbuka' => array_sum(array_map(
                fn (string $status): int => $perStatus[$status] ?? 0,
                LetterRequest::OPEN_STATUSES,
            )),
            'terbit' => $perStatus[LetterRequest::STATUS_ISSUED] ?? 0,
            'terlambat' => $this->requests->countOverdue(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function complaintSummary(AccessScope $scope): ?array
    {
        if (! $scope->can('pengaduan.lihat')) {
            return null;
        }

        $perStatus = $this->complaints->countByStatus();

        return [
            'terbuka' => $this->complaints->countOpen(),
            'selesai' => $perStatus[Complaint::STATUS_DONE] ?? 0,
            'terlambat' => $this->complaints->countOverdue(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function trend(AccessScope $scope): ?array
    {
        /*
         * Tren dan ketepatan SLA menuntut `laporan.lihat`, bukan `surat.lihat`.
         *
         * Keduanya adalah ukuran KINERJA perangkat desa, bukan data layanan:
         * "tepat waktu 72%" adalah angka yang dibaca pimpinan dan pengawas,
         * dan matriks RBAC docs/03 §4.9 memberi akses laporan kepada peran
         * yang berbeda dari yang melayani surat sehari-hari.
         */
        if (! $scope->can('laporan.lihat')) {
            return null;
        }

        return [
            'bulanan' => $this->requests->monthlyTrend(),
            'sla' => $this->requests->slaPerformance(),
        ];
    }

    /**
     * Antrean tugas pengguna aktif — FR-LAP-02.
     *
     * Barisnya menautkan ke layar petugas, jadi berlaku pagar yang sama
     * seperti openComplaints(). Warga memang tidak memegang
     * `surat.verifikasi` maupun `surat.setujui`, tetapi pagar itu dipasang
     * tegas di sini agar tidak bergantung pada susunan izin yang dapat
     * berubah di RoleSeeder tanpa ada yang mengingat layar ini.
     *
     * @return list<array<string, mixed>>|null
     */
    private function awaitingMyAction(AccessScope $scope): ?array
    {
        if ($scope->isFamilyLimited()) {
            return null;
        }

        if (! $scope->can('surat.verifikasi') && ! $scope->can('surat.setujui')) {
            return null;
        }

        return array_map(
            fn (LetterRequest $r): array => [
                'id' => $r->id,
                'ticket_number' => $r->ticket_number,
                'letter_type' => $r->letterType->name,
                'resident_name' => $r->resident->full_name,
                'status' => $r->status,
                'status_label' => LetterRequest::statusLabels()[$r->status] ?? $r->status,
                'is_overdue' => $r->isOverdue(),
                'submitted_at' => $r->submitted_at === null
                    ? null
                    : VillageTime::local($r->submitted_at)->locale('id')->translatedFormat('d F Y'),
            ],
            $this->requests->awaitingMyAction(),
        );
    }

    /**
     * Daftar pengaduan terbuka — ANTREAN KERJA, bukan ringkasan pribadi.
     *
     * Tidak dikirim kepada pengguna berlingkup keluarga (warga) meski ia
     * memegang `pengaduan.lihat`: setiap barisnya menautkan ke layar PETUGAS,
     * yang sejak Sprint 7 dijaga middleware `petugas` dan akan menolaknya.
     * Tautan yang menuntun ke penolakan lebih buruk daripada tautan yang
     * tidak ada (KEP-012) — dan pengaduannya sendiri memang sudah punya
     * tempatnya di portal warga.
     *
     * Kartu angkanya TETAP ada: "berapa pengaduan saya yang masih terbuka"
     * adalah keterangan yang sah baginya.
     *
     * @return list<array<string, mixed>>|null
     */
    private function openComplaints(AccessScope $scope): ?array
    {
        if (! $scope->can('pengaduan.lihat') || $scope->isFamilyLimited()) {
            return null;
        }

        return $this->complaints->openForDashboard()
            ->map(ComplaintPresenter::row(...))
            ->all();
    }

    /**
     * Komposisi penduduk — piramida dari tabel ringkasan `population_stats`.
     *
     * Dibaca dari tabel ringkasan dan bukan dari tabel penduduk, dengan
     * penyamaran sel < 5 tetap berlaku (ADR-008): dashboard dibuka di ruang
     * rapat dan sering diproyeksikan ke dinding, dan kelompok umur yang hanya
     * berisi dua orang di satu dusun menunjuk orang tertentu.
     *
     * @return array<string, mixed>|null
     */
    private function composition(AccessScope $scope): ?array
    {
        if (! $scope->can('statistik.lihat')) {
            return null;
        }

        $summary = $this->statistics->summary();

        return [
            'total' => $summary['total'],
            'piramida' => $summary['pyramid'],
            'computed_at' => $summary['computed_at'],
        ];
    }
}
