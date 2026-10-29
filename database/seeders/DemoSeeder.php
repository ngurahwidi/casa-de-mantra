<?php

namespace Database\Seeders;

use App\Enums\MealPlan;
use App\Enums\UnitStatus;
use App\Models\Amenity;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoSeeder extends Seeder
{
    /**
     * Create a sellable demo room type: one unit, a Non-Refundable rate plan and a base price.
     *
     * Never runs in production. Records are matched by slug/code/name, so re-running is safe.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder skipped: demo data is not seeded in production.');

            return;
        }

        $policy = CancellationPolicy::where('name', CancellationPolicy::NON_REFUNDABLE)->first()
            ?? throw new RuntimeException('Run CancellationPolicySeeder before DemoSeeder.');

        $roomType = RoomType::firstOrCreate(
            ['slug' => 'demo-pool-villa'],
            [
                'name' => 'Demo Pool Villa',
                'description' => 'A two-bedroom villa with a private pool and garden. Demo data for development.',
                'max_adults' => 4,
                'max_children' => 2,
                'bedrooms' => 2,
                'bathrooms' => 2,
                'size_m2' => 180,
                'is_published' => true,
            ],
        );

        $roomType->amenities()->syncWithoutDetaching(Amenity::pluck('id'));

        Unit::firstOrCreate(
            ['room_type_id' => $roomType->id, 'code' => 'VILLA-1'],
            ['status' => UnitStatus::Active],
        );

        $ratePlan = RatePlan::firstOrCreate(
            ['room_type_id' => $roomType->id, 'name' => CancellationPolicy::NON_REFUNDABLE],
            [
                'cancellation_policy_id' => $policy->id,
                'meal_plan' => MealPlan::RoomOnly,
                'min_stay' => 1,
                'max_stay' => null,
                'is_active' => true,
            ],
        );

        RateRule::firstOrCreate(
            ['rate_plan_id' => $ratePlan->id, 'name' => 'Base Price'],
            [
                'start_date' => now(PropertyProfile::current()->timezone)->startOfYear()->toDateString(),
                'end_date' => null,
                'days_of_week_mask' => RateRule::ALL_DAYS,
                'price_per_night' => 2500000,
                'min_stay_override' => null,
                'priority' => 0,
            ],
        );
    }
}
