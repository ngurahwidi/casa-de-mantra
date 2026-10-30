<?php

namespace App\Actions\Reservation;

use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Exceptions\Reservation\ReasonRequiredException;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservation\ReservationStateMachine;

/**
 * An admin declines a pending request.
 *
 * The reason is stored on the reservation (it may be shown to the guest) and as the note of
 * the status log entry. A pending request holds no unit, so nothing has to be released.
 */
class RejectReservation
{
    public function __construct(private readonly ReservationStateMachine $stateMachine) {}

    /**
     * @throws ReasonRequiredException when the reason is blank
     * @throws InvalidStatusTransitionException when the reservation is no longer pending
     */
    public function handle(Reservation $reservation, User $admin, string $reason): Reservation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ReasonRequiredException::forRejection();
        }

        $reservation->reject_reason = $reason;

        return $this->stateMachine->transition($reservation, ReservationStatus::Rejected, $admin, $reason);
    }
}
