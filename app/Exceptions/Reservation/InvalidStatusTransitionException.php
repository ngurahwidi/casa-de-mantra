<?php

namespace App\Exceptions\Reservation;

use App\Enums\ReservationStatus;

/**
 * The reservation cannot move from its current status to the requested one.
 *
 * Actions may give a more specific message, e.g. when the state machine would allow the
 * transition but not through that action.
 */
class InvalidStatusTransitionException extends ReservationException
{
    public function __construct(
        public readonly ReservationStatus $from,
        public readonly ReservationStatus $to,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "A reservation cannot change from {$from->value} to {$to->value}.");
    }
}
