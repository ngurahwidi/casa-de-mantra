<?php

namespace Database\Factories;

use App\Enums\TaxFeeAppliesTo;
use App\Enums\TaxFeeType;
use App\Models\TaxFee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxFee>
 */
class TaxFeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => TaxFeeType::Percent,
            'value' => 10,
            'is_included_in_price' => false,
            'applies_to' => TaxFeeAppliesTo::PerNight,
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
