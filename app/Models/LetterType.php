<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Jenis surat — docs/13-katalog-surat.md.
 *
 * Katalog ini adalah konfigurasi, bukan kode: desa dapat menambah jenis surat
 * sendiri tanpa rilis baru (NFR-MNT-05). Karena itu alur persetujuan, isian,
 * dan templatnya disimpan sebagai data.
 */
/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $title_on_letter
 * @property string|null $classification_code
 * @property array<int, array<string, mixed>> $requirements
 * @property array<string, mixed> $form_schema
 * @property string $template_html
 * @property string $number_pattern
 * @property array<int, array<string, mixed>> $approval_flow
 * @property int $sla_days
 * @property int|null $validity_months
 * @property bool $requires_field_check
 * @property string|null $warning_note
 * @property bool $is_public
 * @property bool $is_active
 */
final class LetterType extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'code_active'];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'form_schema' => 'array',
            'approval_flow' => 'array',
            'fee' => 'decimal:2',
            'requires_field_check' => 'boolean',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Seluruh tahap alur, termasuk tahap persetujuan akhir.
     *
     * Dipakai untuk penanda "Tahap 1 dari 2" di layar petugas.
     */
    public function stepCount(): int
    {
        return max(1, count($this->approval_flow));
    }

    /**
     * Tahap PEMERIKSAAN saja — berapa kali verifikasi diperlukan.
     *
     * MENGAPA DIPISAH DARI stepCount(): pada alur F2 (docs/13 §4) tahapnya
     * adalah `verify` oleh Kasi lalu `approve` oleh Kepala Desa. Tahap
     * `approve` BUKAN verifikasi kedua — ia adalah penerbitan suratnya
     * sendiri, dan dijalankan lewat tombol "Setujui & terbitkan".
     *
     * Menghitung `approve` sebagai pemeriksaan membuat pengajuan F2 mandek
     * di status `diajukan`: Kepala Desa menekan Verifikasi, status tidak
     * berubah, dan tombol terbit tidak pernah muncul.
     *
     * Minimum satu: jenis surat tanpa tahap pemeriksaan sekalipun tetap
     * harus diperiksa seseorang sebelum surat keluar.
     */
    public function verificationStepCount(): int
    {
        return max(1, count($this->verificationSteps()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function verificationSteps(): array
    {
        return array_values(array_filter(
            $this->approval_flow,
            fn (array $step): bool => ($step['action'] ?? 'verify') !== 'approve',
        ));
    }

    /** @return array<string, mixed>|null */
    public function step(int $index): ?array
    {
        return $this->verificationSteps()[$index] ?? null;
    }

    /**
     * Peran yang berwenang pada tahap pemeriksaan tertentu.
     *
     * @return array<int, string>
     */
    public function rolesForStep(int $index): array
    {
        $step = $this->step($index);

        // Katalog (docs/13 §8) menulis satu peran sebagai `role`; bentuk
        // `roles` disediakan untuk langkah yang boleh dikerjakan beberapa peran.
        $roles = $step['roles'] ?? $step['role'] ?? null;

        if (is_string($roles) && $roles !== '') {
            return [$roles];
        }

        /*
         * Bila alur tidak mendefinisikan peran, HANYA admin_desa dan
         * kepala_desa yang boleh — gagal tertutup. Mengembalikan daftar
         * kosong akan ditafsirkan "tanpa pembatasan" oleh pemanggil dan
         * membuat siapa pun dapat menerbitkan surat.
         */
        return is_array($roles) && $roles !== []
            ? array_values(array_map('strval', $roles))
            : ['admin_desa', 'kepala_desa'];
    }
}
