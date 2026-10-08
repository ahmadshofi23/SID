<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Menyiapkan riwayat perubahan untuk ditampilkan.
 *
 * Waktu diubah ke zona waktu desa dan diformat di sisi SERVER, bukan dikirim
 * sebagai UTC lalu diformat di peramban. Alasannya: zona waktu desa adalah
 * fakta tentang desa (WIB/WITA/WIT), bukan tentang perangkat yang sedang
 * dipakai. Petugas yang membuka sistem dari luar kota harus tetap melihat
 * waktu desa.
 */
final class HistoryPresenter
{
    /**
     * @param  Collection<int, array{
     *     waktu: Carbon,
     *     aksi: string,
     *     pelaku: string,
     *     peran: string|null,
     *     perubahan: list<array{field: string, lama: string, baru: string}>,
     *     catatan: array<array-key, mixed>|null
     * }>  $entries
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $entries): array
    {
        return $entries->map(fn (array $entry): array => [
            'waktu' => $entry['waktu']
                ->setTimezone(config('sid.timezone'))
                ->locale('id')
                ->translatedFormat('d M Y, H:i').' WIB',
            'aksi' => $entry['aksi'],
            'pelaku' => $entry['pelaku'],
            'perubahan' => $entry['perubahan'],
            'alasan' => $entry['catatan']['alasan'] ?? null,
        ])->values()->all();
    }
}
