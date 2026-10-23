<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Services\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor laporan ke CSV — FR-LAP-07, BR-41, SRS §4.9 butir 3.
 *
 * SATU kelas untuk seluruh laporan, bukan satu exporter per laporan, karena
 * yang membedakan laporan hanyalah isinya — sementara yang TIDAK boleh
 * berbeda adalah dua hal di bawah ini. Satu exporter per laporan berarti
 * keduanya ditulis ulang lima kali, dan pada salinan keempat salah satunya
 * akan terlupakan:
 *
 * 1. SELALU TERCATAT. Setiap ekspor masuk audit log beserta JUMLAH BARIS dan
 *    saringan yang dipakai. Ekspor adalah cara paling mudah memindahkan data
 *    keluar dari sistem; tanpa jejak, tidak ada yang dapat menjelaskan ke
 *    mana datanya pergi.
 *
 * 2. BOM UTF-8 DI DEPAN. Tanpa itu Excel di Windows membaca huruf beraksen
 *    sebagai sampah, dan perangkat desa akan menyimpulkan ekspornya rusak
 *    lalu kembali mengetik ulang angkanya dengan tangan.
 *
 * Berbeda dari ResidentExporter yang mengalirkan puluhan ribu baris penduduk,
 * laporan di sini adalah REKAP — puluhan baris, bukan ribuan. Ia tetap
 * dialirkan dengan streamDownload() demi keseragaman perilaku unduhan, bukan
 * karena ukurannya menuntut itu.
 */
final class ReportExporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function csv(
        string $reportName,
        string $filenamePrefix,
        array $headers,
        array $rows,
        array $filters = [],
    ): StreamedResponse {
        /*
         * Dicatat SEBELUM pengaliran dimulai, bukan sesudah.
         *
         * Balasan yang dialirkan menulis badannya setelah controller selesai;
         * mencatat di dalam callback berarti unduhan yang terputus di tengah
         * — jaringan desa, peramban ditutup — tidak meninggalkan jejak sama
         * sekali, padahal data sudah terkirim sebagian.
         */
        $this->audit->logExport($reportName, count($rows), $filters);

        $filename = sprintf('%s-%s.csv', $filenamePrefix, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Mencegah peramban menebak jenis isi dan menjalankannya sebagai
            // HTML bila suatu saat ada sel yang diawali tanda kurung sudut.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
