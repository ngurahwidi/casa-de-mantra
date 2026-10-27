<?php

namespace Database\Factories;

use App\Models\ReservationNight;
use App\Models\ReservationRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationNight>
 */
class ReservationNightFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_room_id' => ReservationRoom::factory(),
            'night_date' => '2026-12-24',
            'price' => 1500000,
        ];
    }
}
