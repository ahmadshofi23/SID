<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $resident_id
 * @property string $type
 * @property Carbon $mutation_date
 * @property string|null $reason
 * @property-read Resident $resident
 */
final class ResidentMutation extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public const TYPE_BIRTH = 'lahir';

    public const TYPE_DEATH = 'mati';

    public const TYPE_MOVE_IN = 'pindah_datang';

    public const TYPE_MOVE_OUT = 'pindah_keluar';

    public const TYPE_DATA_CHANGE = 'perubahan_data';

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_BIRTH => 'Kelahiran',
            self::TYPE_DEATH => 'Kematian',
            self::TYPE_MOVE_IN => 'Pindah Datang',
            self::TYPE_MOVE_OUT => 'Pindah Keluar',
            self::TYPE_DATA_CHANGE => 'Perubahan Data',
        ];
    }

    protected function casts(): array
    {
        return [
            'mutation_date' => 'date',
            'changes' => 'array',
            'birth_weight' => 'decimal:2',
            'birth_length' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }
}
