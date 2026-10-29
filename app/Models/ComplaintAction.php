<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Tindak lanjut & riwayat status pengaduan — FR-PGD-05.
 *
 * `actor_name` DISALIN dengan alasan yang sama seperti LetterRequestLog:
 * riwayat yang berubah ketika data penggunanya berubah bukan riwayat.
 */
/**
 * @property string $id
 * @property string $complaint_id
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string $note
 * @property bool $is_visible_to_reporter
 * @property Carbon $created_at
 */
final class ComplaintAction extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_visible_to_reporter' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
