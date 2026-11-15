<?php

namespace App\Exceptions\Reservation;

use App\Models\ReservationSource;

/**
 * The booking has already been entered.
 */
class DuplicateReservationException extends ReservationException
{
    public static function external(ReservationSource $source, string $externalId, string $code): self
    {
        return new self("The {$source->name} booking {$externalId} has already been entered as reservation {$code}.");
    }
}
