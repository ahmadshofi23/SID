<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Pii\PiiCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast untuk kolom *_enc (ADR-004).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedPii implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(PiiCipher::class)->decrypt(
            is_resource($value) ? (string) stream_get_contents($value) : (string) $value
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        return [$key => app(PiiCipher::class)->encrypt((string) $value)];
    }
}
