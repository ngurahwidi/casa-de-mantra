<?php

namespace Database\Factories;

use App\Enums\MealPlan;
use App\Models\CancellationPolicy;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatePlan>
 */
class RatePlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            'cancellation_policy_id' => CancellationPolicy::factory(),
            'name' => 'Non-Refundable',
            'meal_plan' => MealPlan::RoomOnly,
            'min_stay' => 1,
            'max_stay' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
