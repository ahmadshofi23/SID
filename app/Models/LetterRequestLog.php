<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Riwayat status pengajuan — FR-SRT-16.
 *
 * `actor_name` DISALIN, tidak dibaca lewat relasi. Riwayat harus tetap
 * terbaca puluhan tahun kemudian, termasuk setelah akun petugasnya dihapus
 * atau namanya berubah. Relasi `actor` hanya untuk menautkan ke profil yang
 * masih ada.
 */
/**
 * @property string $id
 * @property string $request_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $step
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $note
 * @property Carbon $created_at
 */
final class LetterRequestLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<LetterRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(LetterRequest::class, 'request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
