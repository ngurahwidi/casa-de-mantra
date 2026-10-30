<?php

namespace App\Exceptions\Reservation;

use App\Enums\ReservationStatus;

/**
 * A payment cannot be recorded for the reservation, or its amount is invalid.
 */
class PaymentNotAcceptedException extends ReservationException
{
    public static function expired(): self
    {
        return new self('This reservation has expired. Reactivate it before recording a payment.');
    }

    public static function notPayable(ReservationStatus $status): self
    {
        return new self("Payments can only be recorded for reservations awaiting payment or confirmed; this one is {$status->value}.");
    }

    public static function invalidAmount(string $amount): self
    {
        return new self("The payment amount must be a positive number with at most two decimals; got \"{$amount}\".");
    }
}
