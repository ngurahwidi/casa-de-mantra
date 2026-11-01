<?php

namespace App\Models;

use App\Enums\RateRuleType;
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
 * @property RateRuleType $type
 */
#[Fillable([
    'rate_plan_id',
    'type',
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

    /** The rule the Pricing page creates for the base price. */
    public const BASE_PRICE = 'Base Price';

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @param  iterable<int|string>  $isoDays  1 = Monday ... 7 = Sunday
     */
    public static function maskFor(iterable $isoDays): int
    {
        $mask = 0;

        foreach ($isoDays as $day) {
            $mask |= 1 << ((int) $day - 1);
        }

        return $mask;
    }

    /**
     * The days of week the rule covers, 1 = Monday ... 7 = Sunday.
     *
     * @return list<int>
     */
    public function isoDays(): array
    {
        return array_values(array_filter(range(1, 7), fn (int $day): bool => ($this->days_of_week_mask & (1 << ($day - 1))) !== 0));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RateRuleType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'days_of_week_mask' => 'integer',
            'price_per_night' => 'decimal:2',
            'min_stay_override' => 'integer',
            'priority' => 'integer',
        ];
    }
}
