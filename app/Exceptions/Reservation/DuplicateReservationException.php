<?php

namespace App\Exceptions\Reservation;

use App\Enums\ReservationSource;

/**
 * The booking has already been entered.
 */
class DuplicateReservationException extends ReservationException
{
    public static function external(ReservationSource $source, string $externalId, string $code): self
    {
        return new self("The {$source->value} booking {$externalId} has already been entered as reservation {$code}.");
    }
}
