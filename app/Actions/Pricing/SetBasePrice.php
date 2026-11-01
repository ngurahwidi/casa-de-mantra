<?php

namespace App\Actions\Pricing;

use App\Enums\MealPlan;
use App\Enums\RateRuleType;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sets the price every night of a room type falls back to, and its minimum stay.
 *
 * Rate plans are hidden from the admin. The first time a base price is set, the room type gets
 * its Non-Refundable rate plan (room only, active, linked to the Non-Refundable policy) and a
 * "Base Price" rule for every day of the week from today, with no end date and the lowest
 * priority. Later calls change the price and minimum stay only; the start date is kept.
 *
 * The minimum stay belongs to the rate plan, so it applies to every night unless a seasonal
 * or special-dates price overrides it.
 */
class SetBasePrice
{
    /**
     * @param  string  $pricePerNight  non-negative decimal, e.g. "1500000"
     *
     * @throws RuntimeException when the Non-Refundable cancellation policy has not been seeded
     */
    public function handle(RoomType $roomType, string $pricePerNight, int $minStay = 1): RateRule
    {
        if (! is_numeric($pricePerNight) || $pricePerNight < 0) {
            throw new InvalidArgumentException("Price {$pricePerNight} must be a non-negative number.");
        }

        if ($minStay < 1) {
            throw new InvalidArgumentException('The minimum stay is at least one night.');
        }

        return DB::transaction(function () use ($roomType, $pricePerNight, $minStay): RateRule {
            // Serialises first-time setup, so two admins cannot create two rate plans.
            RoomType::query()->whereKey($roomType->getKey())->lockForUpdate()->firstOrFail();

            $ratePlan = $roomType->nonRefundableRatePlan()->first() ?? $this->createRatePlan($roomType);
            $ratePlan->update(['min_stay' => $minStay]);

            $rule = $ratePlan->rateRules()->where('type', RateRuleType::Base)->first()
                ?? $ratePlan->rateRules()->make([
                    'type' => RateRuleType::Base,
                    'name' => RateRule::BASE_PRICE,
                    'start_date' => now(PropertyProfile::current()->timezone)->toDateString(),
                    'end_date' => null,
                    'days_of_week_mask' => RateRule::ALL_DAYS,
                    'min_stay_override' => null,
                    'priority' => RateRuleType::Base->lowestPriority(),
                ]);

            $rule->price_per_night = $pricePerNight;
            $rule->save();

            return $rule;
        });
    }

    private function createRatePlan(RoomType $roomType): RatePlan
    {
        $policy = CancellationPolicy::query()->where('name', CancellationPolicy::NON_REFUNDABLE)->first()
            ?? throw new RuntimeException('The Non-Refundable cancellation policy is missing. Run CancellationPolicySeeder.');

        return $roomType->ratePlans()->create([
            'cancellation_policy_id' => $policy->getKey(),
            'name' => CancellationPolicy::NON_REFUNDABLE,
            'meal_plan' => MealPlan::RoomOnly,
            'min_stay' => 1,
            'max_stay' => null,
            'is_active' => true,
        ]);
    }
}
