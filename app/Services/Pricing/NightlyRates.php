<?php

namespace App\Services\Pricing;

use App\Models\RatePlan;
use App\Models\RateRule;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the rate rule that sets the price of each night under a rate plan.
 *
 * A night takes the highest-priority rule whose date range and days of week cover it; on equal
 * priority the rule starting latest wins, then the newest. CalculatePrice prices stays with it
 * and the admin price calendar previews it, so both always agree.
 */
class NightlyRates
{
    /**
     * @param  string  $start  first night as Y-m-d
     * @param  string  $end  Y-m-d, exclusive
     * @return array<string, RateRule|null> every night of [start, end) as Y-m-d, null when no rule covers it
     */
    public function forPeriod(RatePlan $ratePlan, string $start, string $end): array
    {
        $rules = $ratePlan->rateRules()
            ->where('start_date', '<', $end)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $start))
            ->orderByDesc('priority')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $nights = [];

        foreach (CarbonPeriod::create($start, $end)->excludeEndDate() as $night) {
            $date = $night->toDateString();
            $day = 1 << ($night->dayOfWeekIso - 1);

            $nights[$date] = $rules->first(fn (RateRule $rule) => $rule->start_date->toDateString() <= $date
                && ($rule->end_date === null || $rule->end_date->toDateString() >= $date)
                && ($rule->days_of_week_mask & $day) !== 0);
        }

        return $nights;
    }
}
