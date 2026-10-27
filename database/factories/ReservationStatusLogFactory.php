<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationStatusLog>
 */
class ReservationStatusLogFactory extends Factory
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
            'admin_user_id' => null,
            'from_status' => null,
            'to_status' => ReservationStatus::Pending,
            'note' => null,
        ];
    }
}
