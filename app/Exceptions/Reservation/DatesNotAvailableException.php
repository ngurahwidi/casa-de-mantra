<?php

namespace App\Exceptions\Reservation;

/**
 * The stay cannot be booked for the requested dates.
 *
 * Messages are written for guests and may be shown on the public site.
 */
class DatesNotAvailableException extends ReservationException
{
    public static function taken(): self
    {
        return new self('The selected dates are not available.');
    }

    public static function inThePast(): self
    {
        return new self('Check-in cannot be in the past.');
    }
}
