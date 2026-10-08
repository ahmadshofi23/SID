<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Definisi kolom berkas impor penduduk — FR-PDK-11.
 *
 * > **ASUMSI (belum dikonfirmasi):** dipakai template resmi kami, bukan format
 * > ekspor sistem lain. Pemetaan kolom dipisahkan ke kelas ini justru agar
 * > format lain (OpenSID, Prodeskel) dapat ditambahkan kelak tanpa menyentuh
 * > logika impor — cukup kelas skema baru.
 */
final class ResidentImportSchema
{
    /**
     * Kolom template: kunci internal => [judul kolom, wajib?, keterangan].
     *
     * @var array<string, array{0: string, 1: bool, 2: string}>
     */
    public const COLUMNS = [
        'nik' => ['NIK', true, '16 angka sesuai KTP'],
        'nama_lengkap' => ['Nama Lengkap', true, 'Sesuai dokumen resmi'],
        'tempat_lahir' => ['Tempat Lahir', false, ''],
        'tanggal_lahir' => ['Tanggal Lahir', true, 'Format: YYYY-MM-DD atau DD/MM/YYYY'],
        'jenis_kelamin' => ['Jenis Kelamin', true, 'L atau P'],
        'nomor_kk' => ['Nomor KK', false, '16 angka. Kosongkan bila belum punya KK'],
        'hubungan_keluarga' => ['Hubungan Keluarga', true, 'Kepala Keluarga, Istri, Anak, ...'],
        'alamat' => ['Alamat', false, ''],
        'rt' => ['RT', false, 'Kode RT, mis. 001'],
        'rw' => ['RW', false, 'Kode RW, mis. 002'],
        'dusun' => ['Dusun', false, 'Nama dusun'],
        'agama' => ['Agama', false, 'Islam, Kristen, ...'],
        'status_perkawinan' => ['Status Perkawinan', false, 'Belum Kawin, Kawin Tercatat, ...'],
        'pendidikan' => ['Pendidikan', false, ''],
        'pekerjaan' => ['Pekerjaan', false, ''],
        'golongan_darah' => ['Golongan Darah', false, 'A, B, AB, O'],
        'nama_ayah' => ['Nama Ayah', false, ''],
        'nama_ibu' => ['Nama Ibu', false, ''],
        'telepon' => ['Telepon', false, ''],
        'kewarganegaraan' => ['Kewarganegaraan', false, 'WNI atau WNA. Kosong = WNI'],
    ];

    /** @return list<string> */
    public static function headers(): array
    {
        return array_map(fn (array $c): string => $c[0], array_values(self::COLUMNS));
    }

    /** @return list<string> */
    public static function requiredHeaders(): array
    {
        return array_values(array_map(
            fn (array $c): string => $c[0],
            array_filter(self::COLUMNS, fn (array $c): bool => $c[1]),
        ));
    }

    /**
     * Petakan judul kolom berkas ke kunci internal.
     *
     * Pencocokan sengaja longgar — tidak peduli huruf besar/kecil, spasi
     * berlebih, atau tanda baca. Petugas yang menambahkan satu spasi di judul
     * kolom tidak seharusnya kehilangan seluruh berkasnya.
     *
     * @param  list<string>  $fileHeaders
     * @return array<int, string> indeks kolom => kunci internal
     */
    public static function mapHeaders(array $fileHeaders): array
    {
        $lookup = [];

        foreach (self::COLUMNS as $key => [$title]) {
            $lookup[self::normalize($title)] = $key;
        }

        $map = [];

        foreach ($fileHeaders as $index => $header) {
            $normalized = self::normalize((string) $header);

            if (isset($lookup[$normalized])) {
                $map[$index] = $lookup[$normalized];
            }
        }

        return $map;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }
}
