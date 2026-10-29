<?php

namespace App\Models;

use Database\Factories\RateRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property string $price_per_night
 */
#[Fillable([
    'rate_plan_id',
    'name',
    'start_date',
    'end_date',
    'days_of_week_mask',
    'price_per_night',
    'min_stay_override',
    'priority',
])]
class RateRule extends Model
{
    /** @use HasFactory<RateRuleFactory> */
    use HasFactory, HasUuids;

    /** Monday = 1, Tuesday = 2, Wednesday = 4, Thursday = 8, Friday = 16, Saturday = 32, Sunday = 64. */
    public const ALL_DAYS = 127;

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_of_week_mask' => 'integer',
            'price_per_night' => 'decimal:2',
            'min_stay_override' => 'integer',
            'priority' => 'integer',
        ];
    }
}
