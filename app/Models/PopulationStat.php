<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $region_id
 * @property string|null $gender
 * @property string|null $age_group
 * @property int $total
 * @property Carbon $computed_at
 */
final class PopulationStat extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['computed_at' => 'datetime', 'total' => 'integer'];
    }
}
