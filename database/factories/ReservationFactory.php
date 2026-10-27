<?php

namespace Database\Factories;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Three nights at 1,500,000; tax is inactive so tax_total stays 0.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::lower(Str::random(8)),
            'guest_id' => Guest::factory(),
            'status' => ReservationStatus::Pending,
            'source' => ReservationSource::DirectWeb,
            'check_in' => '2026-12-24',
            'check_out' => '2026-12-27',
            'adults' => 2,
            'children' => 0,
            'special_requests' => null,
            'currency' => 'IDR',
            'subtotal' => 4500000,
            'tax_total' => 0,
            'total' => 4500000,
            'payment_status' => ReservationPaymentStatus::Unpaid,
            'expires_at' => null,
            'confirmed_at' => null,
            'ota_blocked_at' => null,
            'reject_reason' => null,
            'external_source' => null,
            'external_id' => null,
            'policy_snapshot' => null,
        ];
    }

    public function awaitingPayment(): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::AwaitingPayment,
            'expires_at' => now()->addDay(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Confirmed,
            'payment_status' => ReservationPaymentStatus::Paid,
            'confirmed_at' => now(),
        ]);
    }
}
