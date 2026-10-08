<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Family;
use App\Models\ImportJob;
use App\Models\Region;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Population\StatisticsService;
use App\Support\Pii\Nik;
use App\Support\ReferenceData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Impor massal penduduk — FR-PDK-11, UC-04.
 *
 * Dijalankan dalam DUA tahap yang terpisah, dan pemisahan itu disengaja:
 *
 *   1. PRA-VALIDASI — berkas dibaca seluruhnya, setiap baris diperiksa, tetapi
 *      TIDAK ADA yang disimpan. Hasilnya: daftar baris bermasalah beserta
 *      nomor baris, nama kolom, dan sebabnya.
 *   2. EKSEKUSI — hanya dijalankan setelah petugas melihat laporan tahap 1 dan
 *      menyetujuinya.
 *
 * Mengapa tidak langsung impor saja lalu laporkan yang gagal? Karena impor
 * sebagian membuat basis data berada di keadaan setengah jadi: sebagian warga
 * masuk, sebagian tidak, dan petugas tidak punya cara mengetahui mana yang
 * mana selain memeriksa satu per satu. Memperlihatkan masalahnya lebih dulu
 * jauh lebih murah daripada membersihkannya kemudian.
 */
final class ResidentImporter
{
    private const CHUNK = 200;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ResidentRepository $residents,
        private readonly FamilyRepository $families,
        private readonly AuditLogger $audit,
        private readonly StatisticsService $statistics,
    ) {}

    /**
     * Tahap 1 — periksa seluruh berkas tanpa menyimpan apa pun.
     */
    public function preValidate(ImportJob $job): void
    {
        $job->update(['status' => ImportJob::STATUS_VALIDATING]);

        try {
            $path = Storage::disk('local')->path($job->storage_path);

            $errors = [];
            $total = 0;
            $valid = 0;
            $seenNik = [];
            $map = null;

            foreach ($this->reader->rows($path) as $lineNumber => $cells) {
                if ($map === null) {
                    $map = ResidentImportSchema::mapHeaders($cells);
                    $missing = $this->missingHeaders($map);

                    if ($missing !== []) {
                        $job->update([
                            'status' => ImportJob::STATUS_FAILED,
                            'failure_reason' => 'Kolom wajib tidak ditemukan: '
                                .implode(', ', $missing)
                                .'. Gunakan template resmi yang dapat diunduh di halaman impor.',
                        ]);

                        return;
                    }

                    continue;
                }

                if ($this->isBlank($cells)) {
                    continue;
                }

                $total++;
                $row = $this->extract($cells, $map);
                $rowErrors = $this->validateRow($row, $lineNumber, $seenNik);

                if ($rowErrors === []) {
                    $valid++;
                    $seenNik[$row['nik']] = $lineNumber;
                } else {
                    // Maksimum 500 galat dicatat: daftar yang lebih panjang
                    // tidak menolong siapa pun, dan menandakan berkasnya
                    // memang perlu diperbaiki dari sumbernya.
                    if (count($errors) < 500) {
                        $errors = [...$errors, ...$rowErrors];
                    }
                }
            }

            $job->update([
                'status' => ImportJob::STATUS_REVIEW,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'invalid_rows' => $total - $valid,
                'errors' => $errors,
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => ImportJob::STATUS_FAILED,
                'failure_reason' => 'Berkas tidak dapat dibaca: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Tahap 2 — simpan baris yang sah.
     */
    public function execute(ImportJob $job): void
    {
        $job->update([
            'status' => ImportJob::STATUS_PROCESSING,
            'processed_rows' => 0,
        ]);

        try {
            $path = Storage::disk('local')->path($job->storage_path);

            $map = null;
            $seenNik = [];
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $processed = 0;
            $buffer = [];

            foreach ($this->reader->rows($path) as $lineNumber => $cells) {
                if ($map === null) {
                    $map = ResidentImportSchema::mapHeaders($cells);

                    continue;
                }

                if ($this->isBlank($cells)) {
                    continue;
                }

                $row = $this->extract($cells, $map);

                if ($this->validateRow($row, $lineNumber, $seenNik) !== []) {
                    $skipped++;
                    $processed++;

                    continue;
                }

                $seenNik[$row['nik']] = $lineNumber;
                $buffer[] = $row;

                if (count($buffer) >= self::CHUNK) {
                    [$c, $u] = $this->persist($buffer);
                    $created += $c;
                    $updated += $u;
                    $processed += count($buffer);
                    $buffer = [];

                    $job->update(['processed_rows' => $processed]);
                }
            }

            if ($buffer !== []) {
                [$c, $u] = $this->persist($buffer);
                $created += $c;
                $updated += $u;
                $processed += count($buffer);
            }

            $job->update([
                'status' => ImportJob::STATUS_DONE,
                'processed_rows' => $processed,
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'finished_at' => now(),
            ]);

            $this->audit->log('resident.imported', context: [
                'import_job_id' => $job->id,
                'berkas' => $job->original_name,
                'ditambah' => $created,
                'diperbarui' => $updated,
                'dilewati' => $skipped,
            ]);

            $this->statistics->refresh();
        } catch (Throwable $e) {
            $job->update([
                'status' => ImportJob::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * Simpan satu kelompok baris.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{0: int, 1: int} [ditambah, diperbarui]
     */
    private function persist(array $rows): array
    {
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, &$created, &$updated): void {
            foreach ($rows as $row) {
                $existing = $this->residents->findByNikIgnoringScope($row['nik']);
                $family = $this->resolveFamily($row);
                $region = $this->resolveRegion($row);

                $attributes = [
                    'full_name' => $row['nama_lengkap'],
                    'birth_place' => $row['tempat_lahir'] ?: null,
                    'birth_date' => $this->parseDate($row['tanggal_lahir']),
                    'gender' => strtoupper($row['jenis_kelamin']) === 'P' ? 'P' : 'L',
                    'family_id' => $family?->id,
                    'region_id' => $region?->id,
                    'address' => $row['alamat'] ?: $family?->address,
                    'family_relation_code' => $this->lookup('family_relations', $row['hubungan_keluarga'])
                        ?? 'famili_lain',
                    'religion_code' => $this->lookup('religions', $row['agama']),
                    'marital_status_code' => $this->lookup('marital_statuses', $row['status_perkawinan']),
                    'education_code' => $this->lookup('educations', $row['pendidikan']),
                    'occupation_code' => $this->lookup('occupations', $row['pekerjaan']),
                    'blood_type_code' => $this->lookup('blood_types', $row['golongan_darah']),
                    'father_name' => $row['nama_ayah'] ?: null,
                    'mother_name' => $row['nama_ibu'] ?: null,
                    'phone' => $row['telepon'] ?: null,
                    'citizenship' => strtoupper($row['kewarganegaraan'] ?: 'WNI') === 'WNA' ? 'WNA' : 'WNI',
                ];

                if ($existing !== null) {
                    /*
                     * Baris yang NIK-nya sudah ada DIPERBARUI, bukan ditolak.
                     *
                     * Impor ulang adalah kejadian normal: desa memperbaiki
                     * berkas sumbernya lalu mengunggah lagi. Menolak akan
                     * memaksa petugas membuang baris yang sudah benar.
                     */
                    $before = $existing->getOriginal();
                    $existing->fill($attributes);
                    $existing->save();

                    if ($existing->wasChanged()) {
                        $this->audit->logModelChange('resident.updated', $existing, $before);
                        $updated++;
                    }

                    continue;
                }

                $this->residents->createFromImport($row['nik'], $attributes);
                $created++;
            }
        });

        return [$created, $updated];
    }

    /** @param  array<string, string>  $row */
    private function resolveFamily(array $row): ?Family
    {
        if ($row['nomor_kk'] === '') {
            return null;
        }

        $kk = Nik::normalize($row['nomor_kk']);
        $existing = $this->families->findByKkNumberIgnoringScope($kk);

        if ($existing !== null) {
            return $existing;
        }

        $region = $this->resolveRegion($row);

        return $this->families->createFromImport($kk, [
            'region_id' => $region?->id,
            'address' => $row['alamat'] ?: '-',
            'rt' => $row['rt'] ?: null,
            'rw' => $row['rw'] ?: null,
        ]);
    }

    /** @param  array<string, string>  $row */
    private function resolveRegion(array $row): ?Region
    {
        if ($row['rt'] === '') {
            return null;
        }

        /*
         * Wilayah dicocokkan, TIDAK dibuat otomatis.
         *
         * Membuat dusun/RW/RT dari berkas impor terasa membantu, tetapi satu
         * salah ketik akan melahirkan wilayah hantu — dan wilayah menentukan
         * hak akses Kepala Dusun. Baris yang wilayahnya tidak dikenali tetap
         * masuk, hanya tanpa wilayah, dan muncul di laporan anomali.
         */
        $query = Region::query()->where('level', 'rt')->where('code', $row['rt']);

        if ($row['dusun'] !== '') {
            $query->whereHas('parent.parent', fn ($q) => $q->where('name', 'like', '%'.$row['dusun'].'%'));
        }

        return $query->first();
    }

    /**
     * Cocokkan nilai teks ke kode referensi.
     *
     * Pencocokan longgar (tanpa huruf besar/kecil & tanda baca) karena berkas
     * dari desa menuliskan "SLTA/Sederajat", "slta", dan "SLTA" sebagai hal
     * yang sama.
     */
    private function lookup(string $type, string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        $needle = $this->slug($value);

        foreach (ReferenceData::get($type) as $code => $label) {
            if ($this->slug($label) === $needle || $this->slug($code) === $needle) {
                return $code;
            }
        }

        return null;
    }

    private function slug(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value))) ?? '';
    }

    /**
     * @param  array<int, string>  $map
     * @return list<string>
     */
    private function missingHeaders(array $map): array
    {
        $found = array_values($map);
        $missing = [];

        foreach (ResidentImportSchema::COLUMNS as $key => [$title, $required]) {
            if ($required && ! in_array($key, $found, true)) {
                $missing[] = $title;
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<int, string>  $map
     * @return array<string, string>
     */
    private function extract(array $cells, array $map): array
    {
        $row = array_fill_keys(array_keys(ResidentImportSchema::COLUMNS), '');

        foreach ($map as $index => $key) {
            $row[$key] = trim($cells[$index] ?? '');
        }

        $row['nik'] = Nik::normalize($row['nik']);

        return $row;
    }

    /** @param  list<string>  $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, int>  $seenNik
     * @return list<array{baris: int, kolom: string, pesan: string}>
     */
    private function validateRow(array $row, int $lineNumber, array $seenNik): array
    {
        $errors = [];

        if ($row['nik'] === '') {
            $errors[] = ['baris' => $lineNumber, 'kolom' => 'NIK', 'pesan' => 'NIK wajib diisi.'];
        } elseif (! Nik::isWellFormed($row['nik'])) {
            $errors[] = [
                'baris' => $lineNumber, 'kolom' => 'NIK',
                'pesan' => sprintf('NIK harus 16 angka, ditemukan %d angka.', mb_strlen($row['nik'])),
            ];
        } elseif (isset($seenNik[$row['nik']])) {
            $errors[] = [
                'baris' => $lineNumber, 'kolom' => 'NIK',
                'pesan' => sprintf('NIK ganda — sudah muncul di baris %d.', $seenNik[$row['nik']]),
            ];
        }

        if (trim($row['nama_lengkap']) === '') {
            $errors[] = [
                'baris' => $lineNumber, 'kolom' => 'Nama Lengkap',
                'pesan' => 'Nama lengkap wajib diisi.',
            ];
        }

        $date = $this->parseDate($row['tanggal_lahir']);

        if ($date === null) {
            $errors[] = [
                'baris' => $lineNumber, 'kolom' => 'Tanggal Lahir',
                'pesan' => 'Tanggal lahir tidak dapat dibaca. Gunakan format YYYY-MM-DD atau DD/MM/YYYY.',
            ];
        } elseif ($date->isFuture()) {
            $errors[] = [
                'baris' => $lineNumber, 'kolom' => 'Tanggal Lahir',
                'pesan' => 'Tanggal lahir melebihi hari ini.',
            ];
        } elseif ($date->diffInYears(now()) > 120) {
            $errors[] = [
                'baris' => $lineNumber, 'kolom' => 'Tanggal Lahir',
                'pesan' => 'Usia melebihi 120 tahun — kemungkinan salah ketik tahun.',
            ];
        }

        if (! in_array(strtoupper(trim($row['jenis_kelamin'])), ['L', 'P'], true)) {
            $errors[] = [
                'baris' => $lineNumber, 'kolom' => 'Jenis Kelamin',
                'pesan' => 'Jenis kelamin harus L atau P.',
            ];
        }

        if ($row['nomor_kk'] !== '' && ! Nik::isWellFormed(Nik::normalize($row['nomor_kk']))) {
            $errors[] = [
                'baris' => $lineNumber, 'kolom' => 'Nomor KK',
                'pesan' => 'Nomor KK harus 16 angka.',
            ];
        }

        return $errors;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'Y/m/d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                // Bandingkan kembali ke format asalnya: tanpa itu, Carbon
                // menerima tanggal mustahil seperti 31/02/2020 dan diam-diam
                // menggesernya ke 02/03/2020.
                if ($date !== null && $date->format($format) === $value) {
                    return $date->startOfDay();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
