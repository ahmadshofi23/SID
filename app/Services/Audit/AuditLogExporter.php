<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Http\Resources\AuditLogPresenter;
use App\Models\AuditLog;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor audit log ke CSV — FR-ADM-05.
 *
 * EKSPOR AUDIT LOG IKUT TERCATAT DI AUDIT LOG
 * -------------------------------------------
 * Itu terdengar berputar, dan justru itu intinya. Ekspor adalah satu-satunya
 * cara memindahkan seluruh riwayat tindakan perangkat desa keluar dari
 * sistem, dan ia dilakukan oleh orang yang paling berwenang di dalamnya.
 * Kalau ada satu tindakan yang harus meninggalkan jejak, ini dia.
 *
 * Akibat yang disengaja: baris `data.exported` yang dihasilkan ekspor ini
 * TIDAK ikut di dalam berkas yang baru saja diunduh — ia dicatat sebelum
 * pengaliran dimulai, tetapi ia lebih baru dari baris terakhir yang
 * dialirkan. Dua ekspor berurutan karena itu tidak akan menghasilkan berkas
 * yang sama persis, dan itu benar: yang kedua memang terjadi setelah yang
 * pertama tercatat.
 *
 * MENGAPA BUKAN ReportExporter YANG SUDAH ADA
 * -------------------------------------------
 * `ReportExporter` menerima `array $rows` — seluruh baris di memori. Ia
 * dirancang untuk REKAP, puluhan baris, dan keterangannya mengatakan itu.
 * Audit log adalah tabel terbesar di sistem: ekspor tanpa saringan pada
 * volume sasaran berarti 1,5 juta baris, dan `array` sebesar itu akan
 * menghabiskan memori PHP sebelum satu byte terkirim. Yang dipakai di sini
 * adalah pola `ResidentExporter`: dialirkan, tidak dikumpulkan.
 */
final class AuditLogExporter
{
    public function __construct(
        private readonly AuditLogReader $reader,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(array $filters): StreamedResponse
    {
        /*
         * Jumlah baris dihitung lebih dulu — satu COUNT(*) yang memang
         * dibayar di sini, lihat AuditLogReader::countForExport().
         *
         * Dan dicatat SEBELUM pengaliran dimulai, bukan di dalam callback:
         * badan balasan yang dialirkan ditulis setelah controller selesai,
         * sehingga unduhan yang terputus di tengah — jaringan desa, peramban
         * ditutup — tidak akan meninggalkan jejak sama sekali, padahal
         * datanya sudah terkirim sebagian.
         */
        $rowCount = $this->reader->countForExport($filters);

        $this->audit->logExport('audit_logs', $rowCount, array_filter(
            $filters,
            fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        $filename = sprintf('log-aktivitas-%s.csv', now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($filters): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            // BOM UTF-8 agar Excel di Windows tidak membaca huruf beraksen
            // sebagai sampah, lalu dianggap berkasnya rusak.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, AuditLogPresenter::csvHeaders());

            $this->reader->eachForExport($filters, function (AuditLog $entry) use ($out): void {
                fputcsv($out, AuditLogPresenter::csvRow($entry));
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            /*
             * Dilarang di-cache. Berkas ini memuat riwayat tindakan seluruh
             * perangkat desa beserta alamat IP-nya; satu salinan yang
             * tertinggal di cache peramban komputer bersama di kantor desa
             * dapat dibuka siapa pun yang memakai komputer itu sesudahnya.
             */
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
