<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Auth\AccessScope;
use App\Models\Resident;
use App\Models\ResidentMutation;
use App\Models\VillageProfile;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Pdf\PdfPaper;
use App\Services\Pdf\PdfRenderer;
use App\Support\ReferenceData;
use App\Support\VillageTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;

/**
 * Buku Induk Penduduk & Buku Mutasi — FR-LAP-06, UC-18.
 *
 * Format mengikuti Permendagri 47/2016 tentang Administrasi Pemerintahan
 * Desa: keduanya adalah BUKU REGISTER, bukan laporan ringkas. Isinya satu
 * baris per orang dan per peristiwa, bernomor urut, dan ditandatangani
 * Kepala Desa di akhir — bentuk yang masih diminta pemeriksa kecamatan dalam
 * rupa cetak.
 *
 * TIGA HAL YANG MENENTUKAN RANCANGAN KELAS INI:
 *
 * 1. NIK TER-MASKING SECARA BAWAAN, sama seperti ResidentExporter. NIK penuh
 *    hanya tercetak bila pengguna memegang `data_pribadi.lihat_penuh`, dan
 *    pemakaiannya dicatat terpisah (RULE-07). Buku induk adalah dokumen yang
 *    difotokopi dan dibawa keluar kantor desa; ia tidak boleh memuat NIK
 *    lengkap seluruh warga hanya karena seseorang menekan tombol cetak.
 *
 * 2. SELALU TERCATAT DI AUDIT LOG beserta jumlah baris (BR-41). Mencetak buku
 *    induk adalah memindahkan seluruh data penduduk ke kertas, dan itu
 *    peristiwa yang harus dapat dijelaskan.
 *
 * 3. DIBATASI LINGKUP. Buku dibangun lewat ResidentRepository, sehingga
 *    Kepala Dusun mencetak buku induk DUSUNNYA. Halaman judulnya karena itu
 *    WAJIB menyebut lingkupnya — buku yang tidak menyebutnya akan disangka
 *    buku induk desa, lalu dipakai sebagai dasar angka yang salah.
 */
final class AdministrationBookService
{
    public function __construct(
        private readonly ResidentRepository $residents,
        private readonly PdfRenderer $renderer,
        private readonly AuditLogger $audit,
    ) {}

    /** Buku Induk Penduduk — Permendagri 47/2016. */
    public function residentRegisterPdf(AccessScope $scope): string
    {
        $full = $scope->can('data_pribadi.lihat_penuh');
        $references = ReferenceData::labels();
        $rows = [];
        $no = 0;

        $this->residents->eachForExport(function (Resident $r) use (
            &$rows, &$no, $references, $full
        ): void {
            $no++;

            $rows[] = [
                'no' => $no,
                'nik' => $full ? ($r->revealNik() ?? $r->nik_masked) : $r->nik_masked,
                'nama' => $r->full_name,
                'kk' => $r->family?->kk_number_masked,
                'kelamin' => $r->gender === 'L' ? 'L' : 'P',
                /*
                 * Tempat lahir boleh kosong — data warisan sering tidak
                 * memuatnya — dan penggabungan polos menghasilkan baris yang
                 * diawali koma (", 20 Des 1975") di buku yang dibaca
                 * pemeriksa kecamatan. Terlihat saat mencetak dengan data
                 * contoh.
                 */
                'lahir' => trim(($r->birth_place ?? '').', '
                    .VillageTime::local($r->birth_date)->locale('id')->translatedFormat('j M Y'), ', '),
                'agama' => $this->label($references['religions'], $r->religion_code),
                'kawin' => $this->label($references['marital_statuses'], $r->marital_status_code),
                'pekerjaan' => $this->label($references['occupations'], $r->occupation_code),
                'wilayah' => $r->region?->displayPath() ?? '-',
            ];
        });

        $this->audit->logExport('buku_induk_penduduk', count($rows), [
            'nik_penuh' => $full,
            'lingkup' => $this->scopeLabel($scope),
        ]);

        /*
         * Pengungkapan NIK dicatat TERPISAH dari ekspornya (RULE-07).
         *
         * Dua peristiwa yang berbeda: "siapa mencetak buku induk" dan "siapa
         * melihat NIK lengkap warga". Menggabungkannya menjadi satu baris
         * membuat pencarian audit atas yang kedua — satu-satunya yang benar-
         * benar sensitif — harus menyaring konteks JSON setiap baris ekspor.
         */
        if ($full && $rows !== []) {
            $this->audit->log(AuditLogger::ACTION_PII_REVEALED, context: [
                'field' => 'nik',
                'alasan' => 'cetak buku induk penduduk',
                'rows' => count($rows),
            ]);
        }

        return $this->render('laporan.buku-induk', [
            'baris' => $rows,
            'nikPenuh' => $full,
            'lingkup' => $this->scopeLabel($scope),
            'judul' => 'Buku Induk Penduduk',
            'subjudul' => 'Permendagri Nomor 47 Tahun 2016',
            'catatan' => $full
                ? 'Buku ini memuat NIK lengkap. Simpan sebagai dokumen terbatas; '
                    .'pencetakannya tercatat dalam log aktivitas sistem.'
                : 'NIK ditampilkan sebagian untuk melindungi data pribadi. '
                    .'Pencetakan buku ini tercatat dalam log aktivitas sistem.',
        ]);
    }

    /** Buku Mutasi Penduduk — Permendagri 47/2016. */
    public function mutationRegisterPdf(AccessScope $scope, Carbon $from, Carbon $to): string
    {
        /*
         * Mutasi disaring lewat subkueri penduduk yang terlihat, bukan dibaca
         * bebas: `resident_mutations` bukan tabel dijaga, tetapi ia menyimpan
         * `resident_id`, dan Kepala Dusun yang mencetak seluruh mutasi desa
         * akan mengetahui siapa saja yang meninggal dan pindah di dusun lain.
         */
        $mutations = ResidentMutation::query()
            ->whereIn('resident_id', $this->residents->visibleIdsQuery())
            ->whereBetween('mutation_date', [$from->toDateString(), $to->toDateString()])
            ->with('resident:id,full_name,nik_masked,region_id')
            ->orderBy('mutation_date')
            ->orderBy('created_at')
            ->get();

        $labels = [
            ResidentMutation::TYPE_BIRTH => 'Lahir',
            ResidentMutation::TYPE_DEATH => 'Meninggal',
            ResidentMutation::TYPE_MOVE_IN => 'Pindah Datang',
            ResidentMutation::TYPE_MOVE_OUT => 'Pindah Keluar',
            ResidentMutation::TYPE_DATA_CHANGE => 'Perubahan Data',
        ];

        $rows = [];
        $no = 0;

        foreach ($mutations as $mutation) {
            $no++;

            $rows[] = [
                'no' => $no,
                'tanggal' => VillageTime::local($mutation->mutation_date)
                    ->locale('id')->translatedFormat('j M Y'),
                'jenis' => $labels[$mutation->type] ?? $mutation->type,
                /*
                 * NIK pada buku mutasi SELALU tersamar, tanpa pengecualian
                 * untuk pemegang `data_pribadi.lihat_penuh`.
                 *
                 * Buku mutasi beredar lebih luas daripada buku induk — ia
                 * dilampirkan pada laporan bulanan ke kecamatan — sementara
                 * yang dibutuhkan pemeriksa hanyalah nama dan peristiwanya.
                 */
                'nik' => $mutation->resident->nik_masked,
                'nama' => $mutation->resident->full_name,
                'wilayah' => $mutation->resident->region?->displayPath() ?? '-',
                'keterangan' => $mutation->reason ?? '-',
            ];
        }

        $this->audit->logExport('buku_mutasi_penduduk', count($rows), [
            'dari' => $from->toDateString(),
            'sampai' => $to->toDateString(),
            'lingkup' => $this->scopeLabel($scope),
        ]);

        return $this->render('laporan.buku-mutasi', [
            'baris' => $rows,
            'lingkup' => $this->scopeLabel($scope),
            'judul' => 'Buku Mutasi Penduduk',
            'subjudul' => sprintf(
                'Periode %s s.d. %s · Permendagri Nomor 47 Tahun 2016',
                VillageTime::local($from)->locale('id')->translatedFormat('j F Y'),
                VillageTime::local($to)->locale('id')->translatedFormat('j F Y'),
            ),
            'catatan' => 'NIK ditampilkan sebagian. Buku ini dilampirkan pada laporan '
                .'bulanan ke kecamatan; pencetakannya tercatat dalam log aktivitas sistem.',
        ]);
    }

    /**
     * Kode referensi menjadi label, TAHAN terhadap kode kosong.
     *
     * Kolom seperti `religion_code` boleh null — data warisan dan impor
     * sering tidak lengkap — dan `$labels[null]` adalah offset null yang
     * sejak PHP 8.4 menimbulkan peringatan deprecation. Peringatan itu tidak
     * terlihat saat pengujian (data uji selalu lengkap) dan baru muncul di
     * log produksi sebanyak satu baris PER PENDUDUK setiap kali buku induk
     * dicetak. Terlihat saat mencetak buku dengan data contoh.
     *
     * @param  array<string, string>  $labels
     */
    private function label(array $labels, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '-';
        }

        return $labels[$code] ?? $code;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data): string
    {
        $village = VillageProfile::current();

        $paper = PdfPaper::fromConfig();

        /*
         * MENDATAR (landscape), berbeda dari surat yang menegak: lebar dan
         * tinggi ditukar di sini, SATU KALI, lalu dipakai baik oleh aturan
         * `@page` di dalam HTML maupun oleh driver PDF. Buku register punya
         * sepuluh kolom; dicetak menegak, kolom terakhir menyempit sampai
         * tidak terbaca dan pemeriksa kecamatan akan mengembalikannya.
         */
        $landscape = new PdfPaper(widthMm: $paper->heightMm, heightMm: $paper->widthMm);

        $html = View::make($view, [
            ...$data,
            'paper' => $landscape,
            'village' => $village,
            'dicetakPada' => VillageTime::local(now())
                ->locale('id')->translatedFormat('j F Y, H.i'),
        ])->render();

        return $this->renderer->render($html, $landscape);
    }

    /**
     * Keterangan lingkup untuk kepala halaman — bukan hiasan.
     *
     * Dua orang dapat mencetak "Buku Induk Penduduk" pada hari yang sama dan
     * memperoleh jumlah baris berbeda, dan itu BENAR: yang satu Admin Desa,
     * yang satu Kepala Dusun. Tanpa keterangan ini keduanya akan dibandingkan
     * sebagai dua versi dari angka yang sama, lalu salah satunya dianggap
     * salah — dan yang dicurigai biasanya sistemnya.
     */
    private function scopeLabel(AccessScope $scope): string
    {
        if ($scope->isFamilyLimited()) {
            return 'Terbatas pada keluarga sendiri';
        }

        if ($scope->isRegionLimited()) {
            return 'Terbatas pada wilayah tugas pencetak';
        }

        return 'Seluruh desa';
    }
}
