<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationLogStatus;
use App\Models\NotificationLog;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
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
            'channel' => NotificationChannel::Email,
            'template' => 'reservation_received',
            'status' => NotificationLogStatus::Queued,
            'error_message' => null,
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => NotificationLogStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => NotificationLogStatus::Failed,
            'error_message' => 'Connection could not be established with host.',
        ]);
    }
}
