<?php

namespace App\Actions\Reservation;

use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservation\ReservationStateMachine;
use App\Services\Reservation\UnitAllocator;
use Illuminate\Support\Facades\DB;

/**
 * An admin accepts a pending request and asks the guest to pay.
 *
 * From this point the reservation holds its dates: each room is given a free unit, and the
 * guest has payment_window_hours (property profile) to pay before it expires.
 *
 * Two approvals for the same dates must not both succeed. Inside one transaction the
 * reservation, its rooms and every unit of their room types are locked (in that order, so
 * concurrent approvals cannot deadlock; see UnitAllocator), and only then is availability
 * checked. A second approval waits on the unit locks until the first commits.
 *
 * The locks are taken before any plain read on purpose. Under REPEATABLE READ, InnoDB fixes a
 * transaction's snapshot at its first non-locking read; reading anything earlier would let the
 * availability check run against a snapshot from before the other approval committed.
 */
class ApproveReservation
{
    public function __construct(
        private readonly UnitAllocator $units,
        private readonly ReservationStateMachine $stateMachine,
    ) {}

    /**
     * @throws InvalidStatusTransitionException when the reservation is no longer pending
     * @throws DatesNotAvailableException when no unit is free or check-in has passed
     */
    public function handle(Reservation $reservation, User $admin, ?string $note = null): Reservation
    {
        $profile = PropertyProfile::current();

        return DB::transaction(function () use ($reservation, $admin, $note, $profile): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReservationStatus::Pending) {
                throw new InvalidStatusTransitionException(
                    $locked->status,
                    ReservationStatus::AwaitingPayment,
                    "Only pending reservations can be approved; this one is {$locked->status->value}.",
                );
            }

            if ($locked->check_in->toDateString() < now($profile->timezone)->toDateString()) {
                throw DatesNotAvailableException::inThePast();
            }

            $rooms = $this->units->allocate($locked);

            $reservation->expires_at = now()->addHours($profile->payment_window_hours);

            $this->stateMachine->transition($reservation, ReservationStatus::AwaitingPayment, $admin, $note);

            return $reservation->setRelation('reservationRooms', $rooms);
        });
    }
}
