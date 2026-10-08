<?php

declare(strict_types=1);

namespace App\Services\Population;

use App\Models\Resident;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Support\ReferenceData;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor data penduduk — FR-PDK-12.
 *
 * Tiga hal yang menentukan rancangan kelas ini:
 *
 * 1. **Selalu tercatat.** Setiap ekspor masuk audit log beserta jumlah baris
 *    dan saringan yang dipakai (SRS §4.9 butir 3). Ekspor adalah cara paling
 *    mudah memindahkan data pribadi ribuan warga keluar dari sistem; tanpa
 *    jejak, tidak ada yang dapat menjelaskan ke mana datanya pergi.
 *
 * 2. **NIK ter-masking secara bawaan.** NIK penuh hanya disertakan bila
 *    pengguna punya izin `data_pribadi.lihat_penuh`, dan itu dicatat terpisah.
 *
 * 3. **Dialirkan, tidak dikumpulkan.** Baris ditulis langsung ke keluaran,
 *    sehingga ekspor 20.000 penduduk tidak menahan seluruh data di memori.
 */
final class ResidentExporter
{
    public function __construct(
        private readonly ResidentRepository $residents,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(array $filters, bool $includeFullNik = false): StreamedResponse
    {
        $references = ReferenceData::labels();
        $rowCount = $this->residents->countAll();

        $this->audit->logExport('residents', $rowCount, [
            ...$filters,
            'nik_penuh' => $includeFullNik,
        ]);

        $filename = sprintf('penduduk-%s.csv', now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($references, $includeFullNik): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            // BOM UTF-8 agar Excel di Windows membaca huruf beraksen dengan
            // benar. Tanpa ini, "Ma'rufah" muncul sebagai "Maâ€™rufah".
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'NIK', 'Nama Lengkap', 'Tempat Lahir', 'Tanggal Lahir', 'Umur',
                'Jenis Kelamin', 'Nomor KK', 'Hubungan Keluarga', 'Alamat',
                'Wilayah', 'Agama', 'Status Perkawinan', 'Pendidikan', 'Pekerjaan',
                'Kewarganegaraan', 'Status',
            ]);

            $this->residents->eachForExport(function (Resident $r) use (
                $out, $references, $includeFullNik
            ): void {
                fputcsv($out, [
                    $includeFullNik ? ($r->revealNik() ?? '') : $r->nik_masked,
                    $r->full_name,
                    $r->birth_place,
                    $r->birth_date->format('Y-m-d'),
                    $r->age(),
                    $r->gender === 'L' ? 'Laki-laki' : 'Perempuan',
                    $r->family?->kk_number_masked,
                    $references['family_relations'][$r->family_relation_code] ?? '',
                    $r->address,
                    $r->region?->displayPath(),
                    $references['religions'][$r->religion_code] ?? '',
                    $references['marital_statuses'][$r->marital_status_code] ?? '',
                    $references['educations'][$r->education_code] ?? '',
                    $references['occupations'][$r->occupation_code] ?? '',
                    $r->citizenship,
                    str_replace('_', ' ', $r->status),
                ]);
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Dilarang di-cache: berkas ini memuat data pribadi.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
