<?php

namespace App\Exceptions\Reservation;

use Carbon\CarbonInterface;

/**
 * The reservation cannot be completed yet because its check-out date has not passed.
 */
class StayNotFinishedException extends ReservationException
{
    public static function forCompletion(CarbonInterface $checkOut): self
    {
        return new self("A reservation can only be completed after its check-out date ({$checkOut->toDateString()}).");
    }
}
