<?php

namespace App\Exceptions\Reservation;

use App\Enums\ReservationStatus;

/**
 * The reservation cannot move from its current status to the requested one.
 */
class InvalidStatusTransitionException extends ReservationException
{
    public function __construct(
        public readonly ReservationStatus $from,
        public readonly ReservationStatus $to,
    ) {
        parent::__construct("A reservation cannot change from {$from->value} to {$to->value}.");
    }
}
