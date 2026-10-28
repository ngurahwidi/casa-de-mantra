<?php

namespace Database\Factories;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'reservation.created',
            'payload' => ['reservation_id' => (string) Str::uuid7()],
            'status' => OutboxEventStatus::Pending,
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => OutboxEventStatus::Processed,
            'processed_at' => now(),
        ]);
    }
}
