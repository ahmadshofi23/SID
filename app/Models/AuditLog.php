<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Audit log append-only ber-hash-chain — ADR-005, FR-ADM-04, RULE-12.
 *
 * Model ini MENOLAK pembaruan & penghapusan di lapis aplikasi. Hak akses MySQL
 * (php artisan sid:harden-audit-log) menolaknya di lapis basis data. Dua lapis,
 * karena log yang dapat diubah tidak bernilai sebagai bukti.
 */
/**
 * @property int $id
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string $action
 * @property array<array-key, mixed>|null $changes
 * @property array<array-key, mixed>|null $context
 * @property string|null $prev_hash
 * @property string $row_hash
 * @property Carbon $created_at
 */
final class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Audit log tidak dapat diubah (append-only).');
        });

        self::deleting(function (): never {
            throw new RuntimeException('Audit log tidak dapat dihapus (append-only).');
        });
    }

    /**
     * Hitung hash baris. Dirantai ke baris sebelumnya sehingga penyisipan,
     * penghapusan, atau perubahan baris di luar aplikasi dapat dideteksi.
     */
    public function computeHash(?string $previousHash): string
    {
        $payload = json_encode([
            'prev' => $previousHash,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'changes' => $this->changes,
            'context' => $this->context,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->format('Y-m-d H:i:s.v'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }
}
