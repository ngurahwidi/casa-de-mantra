<?php

namespace Database\Factories;

use App\Enums\UnitStatus;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
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
            'code' => strtoupper(fake()->unique()->bothify('U-###')),
            'status' => UnitStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => UnitStatus::Inactive]);
    }
}
