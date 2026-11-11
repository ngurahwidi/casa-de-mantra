<?php

namespace Database\Factories;

use App\Enums\AvailabilityBlockSource;
use App\Models\AvailabilityBlock;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityBlock>
 */
class AvailabilityBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'start_date' => '2026-12-24',
            'end_date' => '2026-12-27',
            'source' => AvailabilityBlockSource::Manual,
            'reason' => null,
        ];
    }
}
