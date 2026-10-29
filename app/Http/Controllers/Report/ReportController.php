<?php

declare(strict_types=1);

namespace App\Http\Controllers\Report;

use App\Auth\AccessScope;
use App\Http\Controllers\Controller;
use App\Services\Report\AdministrationBookService;
use App\Services\Report\ReportExporter;
use App\Services\Report\ReportService;
use App\Support\VillageTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan & ekspor — FR-LAP-03 s.d. FR-LAP-07, UC-18.
 *
 * SATU controller untuk empat laporan, bukan empat controller: yang berbeda
 * hanyalah susunan angkanya, sementara yang sama adalah hal-hal yang justru
 * tidak boleh berbeda — pemeriksaan izin, periode, dan pencatatan ekspor.
 *
 * Tanpa konstruktor (KEP-009): ReportService dan AdministrationBookService
 * membawa repository berlingkup, sehingga keduanya WAJIB lewat injeksi method.
 */
final class ReportController extends Controller
{
    public function index(ReportService $reports, AccessScope $scope): Response
    {
        $this->authorize('laporan.lihat');

        [$from, $to] = $reports->defaultPeriod();

        return Inertia::render('Laporan/Index', [
            'penduduk' => $reports->population(),
            'mutasi' => $reports->mutations($from, $to),
            'layanan' => $reports->services($from, $to),
            'pengaduan' => $scope->can('pengaduan.lihat') ? $reports->complaints() : null,
            'periode' => ['dari' => $from->toDateString(), 'sampai' => $to->toDateString()],
            /*
             * Lingkup dikirim ke layar, bukan disimpulkan di Vue dari peran.
             *
             * Dua orang dapat membuka laporan yang sama dan melihat angka
             * berbeda — yang satu Admin Desa, yang satu Kepala Dusun — dan itu
             * BENAR. Layar WAJIB mengatakannya, karena angka tanpa keterangan
             * lingkup akan dibandingkan sebagai dua versi dari angka yang sama
             * lalu salah satunya dianggap salah; yang dicurigai biasanya
             * sistemnya.
             */
            'lingkup' => $scope->isRegionLimited()
                ? 'Wilayah tugas Anda'
                : ($scope->isFamilyLimited() ? 'Keluarga Anda' : 'Seluruh desa'),
            'izin' => [
                'ekspor' => $scope->can('laporan.ekspor'),
                'nik_penuh' => $scope->can('data_pribadi.lihat_penuh'),
            ],
        ]);
    }

    /* ============================== Ekspor =============================== */

    public function exportPopulation(
        ReportService $reports,
        ReportExporter $exporter,
    ): StreamedResponse {
        $this->authorize('laporan.ekspor');

        $data = $reports->population();
        $rows = [];

        foreach ($data['per_wilayah'] as $w) {
            $rows[] = ['Wilayah', $w['region'], $w['total'], $w['laki'], $w['perempuan']];
        }

        foreach ($data['per_kategori'] as $kategori => $baris) {
            foreach ($baris as $b) {
                /*
                 * Kolom laki/perempuan dikosongkan pada baris kategori, bukan
                 * diisi nol: nol berarti "tidak ada laki-laki beragama Islam",
                 * sedangkan yang benar adalah "rincian itu tidak dihitung di
                 * baris ini".
                 */
                $rows[] = [ucfirst(str_replace('_', ' ', $kategori)), $b['label'], $b['jumlah'], '', ''];
            }
        }

        return $exporter->csv(
            'laporan_penduduk',
            'rekap-penduduk',
            ['Kelompok', 'Rincian', 'Jumlah', 'Laki-laki', 'Perempuan'],
            $rows,
            ['total_aktif' => $data['total']],
        );
    }

    public function exportMutations(
        Request $request,
        ReportService $reports,
        ReportExporter $exporter,
    ): StreamedResponse {
        $this->authorize('laporan.ekspor');

        [$from, $to] = $this->period($request, $reports);
        $data = $reports->mutations($from, $to);

        return $exporter->csv(
            'laporan_mutasi',
            'rekap-mutasi',
            ['Jenis Mutasi', 'Jumlah'],
            array_map(
                fn (array $b): array => [$b['label'], $b['jumlah']],
                $data['per_jenis'],
            ),
            ['dari' => $data['dari'], 'sampai' => $data['sampai']],
        );
    }

    public function exportServices(
        Request $request,
        ReportService $reports,
        ReportExporter $exporter,
    ): StreamedResponse {
        $this->authorize('laporan.ekspor');

        [$from, $to] = $this->period($request, $reports);
        $data = $reports->services($from, $to);

        return $exporter->csv(
            'laporan_layanan',
            'rekap-layanan-surat',
            ['Kode', 'Jenis Surat', 'Total', 'Terbit', 'Ditolak', 'Rata-rata Jam', '% Tepat SLA'],
            array_map(
                fn (array $b): array => [
                    $b['code'], $b['name'], $b['total'], $b['selesai'], $b['ditolak'],
                    // Kosong, bukan nol: lihat alasan yang sama di atas.
                    $b['rata_jam'] ?? '',
                    $b['persen_tepat'] ?? '',
                ],
                $data['per_jenis'],
            ),
            ['dari' => $data['dari'], 'sampai' => $data['sampai']],
        );
    }

    public function exportComplaints(
        ReportService $reports,
        ReportExporter $exporter,
    ): StreamedResponse {
        $this->authorize('laporan.ekspor');
        $this->authorize('pengaduan.lihat');

        $data = $reports->complaints();

        return $exporter->csv(
            'laporan_pengaduan',
            'rekap-pengaduan',
            ['Status', 'Jumlah'],
            array_map(
                fn (array $b): array => [$b['label'], $b['jumlah']],
                $data['per_status'],
            ),
            ['terbuka' => $data['terbuka'], 'terlambat' => $data['terlambat']],
        );
    }

    /* ======================== Buku administrasi ========================== */

    /**
     * Buku Induk Penduduk — FR-LAP-06.
     *
     * Menuntut `penduduk.ekspor`, BUKAN `laporan.ekspor`: yang keluar dari
     * sini bukan rekap melainkan satu baris per warga dengan nama, tanggal
     * lahir, dan alamatnya. Itu ekspor data penduduk dalam bentuk lain, dan
     * izinnya harus izin yang sama.
     */
    public function residentRegister(
        AdministrationBookService $books,
        AccessScope $scope,
    ): \Illuminate\Http\Response {
        $this->authorize('laporan.lihat');
        $this->authorize('penduduk.ekspor');

        return $this->pdf(
            $books->residentRegisterPdf($scope),
            'buku-induk-penduduk',
        );
    }

    public function mutationRegister(
        Request $request,
        AdministrationBookService $books,
        ReportService $reports,
        AccessScope $scope,
    ): \Illuminate\Http\Response {
        $this->authorize('laporan.lihat');
        $this->authorize('penduduk.ekspor');

        [$from, $to] = $this->period($request, $reports);

        return $this->pdf(
            $books->mutationRegisterPdf($scope, $from, $to),
            'buku-mutasi-penduduk',
        );
    }

    /* ============================== Bantu ================================ */

    private function pdf(string $contents, string $prefix): \Illuminate\Http\Response
    {
        $filename = sprintf('%s-%s.pdf', $prefix, now()->format('Ymd-His'));

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            /*
             * `inline`, bukan `attachment`: buku register hampir selalu
             * diperiksa di layar lebih dulu sebelum dicetak, dan unduhan yang
             * langsung tersimpan ke folder Unduhan menumpuk sebagai salinan
             * data penduduk di komputer kantor desa.
             */
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Periode dari parameter, dengan bawaan tahun berjalan.
     *
     * Tanggal yang tidak dikenali TIDAK menggagalkan laporan melainkan
     * kembali ke bawaan: laporan yang menolak terbuka karena satu parameter
     * URL salah ketik hanya membuat petugas menyimpulkan menunya rusak.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request, ReportService $reports): array
    {
        [$from, $to] = $reports->defaultPeriod();

        $dari = $this->parseDate($request->string('dari')->toString());
        $sampai = $this->parseDate($request->string('sampai')->toString());

        return [
            $dari ?? $from,
            // Akhir HARI, bukan tengah malam: periode "s.d. 31 Oktober" yang
            // berhenti pukul 00.00 kehilangan seluruh isi tanggal 31.
            $sampai === null ? $to : $sampai->endOfDay(),
        ];
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            // Dibaca pada kalender desa (KEP-010): "1 Oktober" bagi petugas
            // adalah pukul 00.00 WIB, bukan 00.00 UTC yang jatuh 07.00 WIB.
            return VillageTime::local(Carbon::createFromFormat('Y-m-d', $value)->startOfDay());
        } catch (\Throwable) {
            return null;
        }
    }
}
