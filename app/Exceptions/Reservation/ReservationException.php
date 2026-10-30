<?php

namespace App\Exceptions\Reservation;

use DomainException;

/**
 * A reservation operation that is not allowed, e.g. in the reservation's current state or for its dates.
 */
abstract class ReservationException extends DomainException
{
    //
}
