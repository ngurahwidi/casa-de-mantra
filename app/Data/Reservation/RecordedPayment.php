<?php

namespace App\Data\Reservation;

use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\ReservationException;
use App\Models\Payment;
use App\Models\Reservation;

/**
 * The outcome of RecordPayment.
 *
 * The payment is always stored. When it should have confirmed the reservation but the
 * confirmation failed (e.g. the dates were blocked since approval), the reason is kept in
 * $confirmationFailure so the admin can be told and resolve it by hand.
 */
final readonly class RecordedPayment
{
    public function __construct(
        public Payment $payment,
        public Reservation $reservation,
        public ?ReservationException $confirmationFailure = null,
    ) {}

    public function isConfirmed(): bool
    {
        return $this->reservation->status === ReservationStatus::Confirmed;
    }
}
