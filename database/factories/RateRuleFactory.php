<?php

namespace Database\Factories;

use App\Models\RatePlan;
use App\Models\RateRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateRule>
 */
class RateRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_plan_id' => RatePlan::factory(),
            'name' => 'Base Price',
            'start_date' => '2026-01-01',
            'end_date' => null,
            'days_of_week_mask' => RateRule::ALL_DAYS,
            'price_per_night' => 1500000,
            'min_stay_override' => null,
            'priority' => 0,
        ];
    }
}
