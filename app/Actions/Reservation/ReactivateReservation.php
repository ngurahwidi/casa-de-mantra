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
 * An admin gives an expired reservation another payment window, e.g. when the guest pays late.
 *
 * Its dates were released on expiry, so they are checked again under lock exactly as in
 * ApproveReservation: each room gets back its unit if still free, or another free unit of its
 * room type. The guest has payment_window_hours (property profile) again from now.
 */
class ReactivateReservation
{
    public function __construct(
        private readonly UnitAllocator $units,
        private readonly ReservationStateMachine $stateMachine,
    ) {}

    /**
     * @throws InvalidStatusTransitionException when the reservation is not expired
     * @throws DatesNotAvailableException when its dates are taken or check-in has passed
     */
    public function handle(Reservation $reservation, User $admin, ?string $note = null): Reservation
    {
        $profile = PropertyProfile::current();

        return DB::transaction(function () use ($reservation, $admin, $note, $profile): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReservationStatus::Expired) {
                throw new InvalidStatusTransitionException(
                    $locked->status,
                    ReservationStatus::AwaitingPayment,
                    "Only expired reservations can be reactivated; this one is {$locked->status->value}.",
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
