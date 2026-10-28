<?php

namespace Database\Factories;

use App\Models\IntegrationMapping;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationMapping>
 */
class IntegrationMappingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_type' => 'reservation',
            'internal_id' => (string) Str::uuid7(),
            'provider' => 'channel_manager',
            'external_id' => fake()->unique()->numerify('EXT-########'),
            'last_synced_at' => null,
        ];
    }
}
