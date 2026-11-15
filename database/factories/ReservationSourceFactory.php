<?php

namespace Database\Factories;

use App\Enums\ReservationSourceCategory;
use App\Models\ReservationSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active OTA source. The built-in website source is created by its migration;
 * use ReservationSource::website() for it.
 *
 * @extends Factory<ReservationSource>
 */
class ReservationSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'category' => ReservationSourceCategory::Ota,
            'is_active' => true,
        ];
    }

    public function direct(): static
    {
        return $this->state(fn () => [
            'category' => ReservationSourceCategory::Direct,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
