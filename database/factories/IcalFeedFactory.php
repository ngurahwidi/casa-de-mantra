<?php

namespace Database\Factories;

use App\Enums\IcalChannel;
use App\Enums\IcalDirection;
use App\Models\IcalFeed;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IcalFeed>
 */
class IcalFeedFactory extends Factory
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
            'channel' => IcalChannel::Booking,
            'direction' => IcalDirection::Import,
            'url' => fake()->unique()->url(),
            'export_token' => null,
            'last_synced_at' => null,
            'last_status' => null,
        ];
    }

    public function export(): static
    {
        return $this->state(fn () => [
            'direction' => IcalDirection::Export,
            'url' => null,
            'export_token' => Str::lower(Str::random(40)),
        ]);
    }
}
