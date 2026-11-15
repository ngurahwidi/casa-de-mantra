<?php

namespace App\Exceptions\Reservation;

use App\Models\ReservationSource;

/**
 * The reservation source has been deactivated, so new reservations cannot be entered with it.
 */
class InactiveReservationSourceException extends ReservationException
{
    public static function for(ReservationSource $source): self
    {
        return new self("{$source->name} is no longer an active reservation source.");
    }
}
