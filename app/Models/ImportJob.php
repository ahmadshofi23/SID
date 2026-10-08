<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $type
 * @property string $original_name
 * @property string $storage_path
 * @property string $status
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property array<int, array<string, mixed>>|null $errors
 * @property string|null $failure_reason
 * @property string|null $created_by
 * @property Carbon|null $finished_at
 */
final class ImportJob extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    public const STATUS_PENDING = 'menunggu';

    public const STATUS_VALIDATING = 'pra_validasi';

    public const STATUS_REVIEW = 'ditinjau';

    public const STATUS_PROCESSING = 'diproses';

    public const STATUS_DONE = 'selesai';

    public const STATUS_FAILED = 'gagal';

    public const STATUS_CANCELLED = 'dibatalkan';

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** Persentase kemajuan untuk ditampilkan. */
    public function progress(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return (int) min(100, round($this->processed_rows / $this->total_rows * 100));
    }

    public function isFinished(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED],
            true,
        );
    }
}
