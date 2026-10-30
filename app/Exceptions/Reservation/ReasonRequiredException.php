<?php

namespace App\Exceptions\Reservation;

/**
 * An admin action that must be explained was given no reason.
 */
class ReasonRequiredException extends ReservationException
{
    public static function forRejection(): self
    {
        return new self('A reason is required to reject a reservation.');
    }

    public static function forCancellation(): self
    {
        return new self('A reason is required to cancel a reservation.');
    }
}
