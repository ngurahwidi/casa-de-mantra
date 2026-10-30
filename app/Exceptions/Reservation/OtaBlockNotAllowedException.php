<?php

namespace App\Exceptions\Reservation;

use App\Enums\ReservationStatus;

/**
 * The reservation does not hold its dates, so there is nothing to block on the OTAs.
 */
class OtaBlockNotAllowedException extends ReservationException
{
    public static function forStatus(ReservationStatus $status): self
    {
        return new self("Only reservations awaiting payment or confirmed can be marked as blocked on the OTAs; this one is {$status->value}.");
    }
}
