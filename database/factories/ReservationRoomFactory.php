<?php

namespace Database\Factories;

use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationRoom>
 */
class ReservationRoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'room_type_id' => RoomType::factory(),
            'unit_id' => null,
            'rate_plan_id' => RatePlan::factory(),
            'total_price' => 4500000,
        ];
    }
}
