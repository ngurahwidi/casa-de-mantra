<?php

namespace App\Exceptions\Reservation;

use DomainException;

/**
 * A reservation operation that is not allowed in the reservation's current state.
 */
abstract class ReservationException extends DomainException
{
    //
}
