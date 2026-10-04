<?php

declare(strict_types=1);

namespace App\Services\Population;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Riwayat perubahan data — FR-PDK-07, BR-02.
 *
 * Dibaca dari audit_logs, bukan dari tabel riwayat terpisah. Alasannya: audit
 * log sudah append-only dan ber-hash-chain (ADR-005), sehingga riwayat ikut
 * memperoleh jaminan keutuhan yang sama. Tabel riwayat terpisah akan menjadi
 * salinan kedua yang bisa berbeda isinya.
 */
final class ChangeHistoryService
{
    /** Label berbahasa Indonesia untuk nama kolom teknis. */
    private const FIELD_LABELS = [
        'full_name' => 'Nama lengkap',
        'birth_place' => 'Tempat lahir',
        'birth_date' => 'Tanggal lahir',
        'gender' => 'Jenis kelamin',
        'blood_type_code' => 'Golongan darah',
        'religion_code' => 'Agama',
        'marital_status_code' => 'Status perkawinan',
        'family_relation_code' => 'Hubungan dalam keluarga',
        'education_code' => 'Pendidikan',
        'occupation_code' => 'Pekerjaan',
        'citizenship' => 'Kewarganegaraan',
        'father_name' => 'Nama ayah',
        'mother_name' => 'Nama ibu',
        'address' => 'Alamat',
        'phone' => 'Nomor telepon',
        'email' => 'Email',
        'domicile_address' => 'Alamat domisili',
        'status' => 'Status kependudukan',
        'disability_code' => 'Disabilitas',
        'is_poor' => 'Penanda keluarga tidak mampu',
        'notes' => 'Catatan',
        'family_id' => 'Kartu keluarga',
        'region_id' => 'Wilayah',
        'rt' => 'RT',
        'rw' => 'RW',
        'welfare_status_code' => 'Status kesejahteraan',
        'house_ownership_code' => 'Status kepemilikan rumah',
        'name' => 'Nama',
        'code' => 'Kode',
        'head_name' => 'Nama kepala wilayah',
        'path' => 'Jalur wilayah',
    ];

    /** Label aksi untuk ditampilkan ke pengguna. */
    private const ACTION_LABELS = [
        'resident.created' => 'Data penduduk ditambahkan',
        'resident.updated' => 'Data penduduk diubah',
        'resident.deleted' => 'Data penduduk dihapus',
        'resident.restored' => 'Data penduduk dipulihkan',
        'family.created' => 'Kartu keluarga dibuat',
        'family.updated' => 'Kartu keluarga diubah',
        'family.deleted' => 'Kartu keluarga dihapus',
        'family.member_moved' => 'Anggota dipindahkan antar kartu keluarga',
        'region.created' => 'Wilayah ditambahkan',
        'region.updated' => 'Wilayah diubah',
        'region.deleted' => 'Wilayah dihapus',
        'data.pii_revealed' => 'NIK/nomor KK dibuka',
    ];

    /**
     * Riwayat satu entitas, terbaru lebih dulu.
     *
     * @return Collection<int, array{
     *     waktu: Carbon,
     *     aksi: string,
     *     pelaku: string,
     *     peran: string|null,
     *     perubahan: list<array{field: string, lama: string, baru: string}>,
     *     catatan: array<array-key, mixed>|null
     * }>
     */
    public function forEntity(Model $entity, int $limit = 100): Collection
    {
        return AuditLog::query()
            ->where('entity_type', class_basename($entity))
            ->where('entity_id', (string) $entity->getKey())
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'waktu' => $entry->created_at,
                'aksi' => self::ACTION_LABELS[$entry->action] ?? $entry->action,
                'pelaku' => $entry->actor_name ?? 'sistem',
                'peran' => $entry->actor_roles,
                'perubahan' => $this->formatChanges($entry->changes ?? []),
                'catatan' => $entry->context,
            ]);
    }

    /**
     * @param  array<array-key, mixed>  $changes
     * @return list<array{field: string, lama: string, baru: string}>
     */
    private function formatChanges(array $changes): array
    {
        $result = [];

        foreach ($changes as $field => $change) {
            if (! is_string($field)) {
                continue;
            }

            // Nilai ter-masking oleh PiiRedactor tampil apa adanya; itu memang
            // yang ingin diperlihatkan (bahwa field itu berubah, bukan isinya).
            $old = is_array($change) ? ($change['old'] ?? null) : null;
            $new = is_array($change) ? ($change['new'] ?? null) : $change;

            $result[] = [
                'field' => self::FIELD_LABELS[$field] ?? $field,
                'lama' => $this->stringify($old),
                'baru' => $this->stringify($new),
            ];
        }

        return $result;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '(kosong)',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '(data)',
            default => (string) $value,
        };
    }
}
