<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aturan bisnis yang dapat diubah desa dari UI tanpa rilis (NFR-MNT-05).
 */
final class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array', 'updated_at' => 'datetime'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = cache()->remember(
            'settings.all', now()->addHour(),
            fn () => self::query()->pluck('value', 'key')->all()
        );

        return $all[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, ?string $userId = null): void
    {
        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId]
        );

        cache()->forget('settings.all');
    }
}
