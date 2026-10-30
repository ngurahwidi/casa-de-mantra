<?php

namespace App\Actions\Reservation;

use App\Contracts\AvailabilityProvider;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Exceptions\Reservation\PaymentRequiredException;
use App\Models\Guest;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Unit;
use App\Models\User;
use App\Services\Reservation\ReservationStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Confirms a reservation awaiting payment once it has been paid.
 *
 * Fully paid is always enough; a deposit is enough only when the property profile sets
 * confirm_on_deposit. RecordPayment calls this on its own after a payment that qualifies;
 * a payment gateway webhook can do the same later.
 *
 * Availability is checked again under lock, as in ApproveReservation and in the same order
 * (reservation, its rooms, every unit of their room types), so a block added since approval
 * is noticed and concurrent approvals and confirmations cannot deadlock. A room keeps its unit
 * while that unit is still free, otherwise it moves to another free unit of its room type.
 * Run it in a transaction of its own: the locks must come before any plain read, see
 * ApproveReservation.
 *
 * On confirmation the guest record takes the contact details given with this reservation
 * (see CreateReservationRequest). The email is left as is, since guests are matched by it.
 */
class ConfirmReservation
{
    public function __construct(
        private readonly AvailabilityProvider $availability,
        private readonly ReservationStateMachine $stateMachine,
    ) {}

    /**
     * @param  User|null  $actor  the admin who confirmed it; null for system actions
     *
     * @throws InvalidStatusTransitionException when the reservation is not awaiting payment
     * @throws PaymentRequiredException when it has not been paid enough
     * @throws DatesNotAvailableException when its dates are no longer free
     */
    public function handle(Reservation $reservation, ?User $actor = null, ?string $note = null): Reservation
    {
        $profile = PropertyProfile::current();

        return DB::transaction(function () use ($reservation, $actor, $note, $profile): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReservationStatus::AwaitingPayment) {
                throw new InvalidStatusTransitionException(
                    $locked->status,
                    ReservationStatus::Confirmed,
                    "Only reservations awaiting payment can be confirmed; this one is {$locked->status->value}.",
                );
            }

            if (! self::isPaidEnough($locked, $profile)) {
                throw PaymentRequiredException::forConfirmation($locked->payment_status);
            }

            $rooms = ReservationRoom::query()
                ->whereBelongsTo($locked)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            Unit::query()
                ->whereIn('room_type_id', $rooms->pluck('room_type_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $assigned = [];

            foreach ($rooms as $room) {
                $free = $this->availability
                    ->availableUnits($room->roomType()->firstOrFail(), $locked->check_in, $locked->check_out, $locked)
                    ->reject(fn (Unit $unit) => in_array($unit->id, $assigned, true));

                $unit = $free->firstWhere('id', $room->unit_id) ?? $free->first();

                if ($unit === null) {
                    throw DatesNotAvailableException::taken();
                }

                $assigned[] = $unit->id;
                $room->update(['unit_id' => $unit->id]);
            }

            $reservation->confirmed_at = now();

            $this->stateMachine->transition($reservation, ReservationStatus::Confirmed, $actor, $note);

            $this->refreshGuest($locked);

            return $reservation->setRelation('reservationRooms', $rooms);
        });
    }

    /**
     * Whether the payments recorded so far allow the reservation to be confirmed.
     */
    public static function isPaidEnough(Reservation $reservation, PropertyProfile $profile): bool
    {
        return match ($reservation->payment_status) {
            ReservationPaymentStatus::Paid => true,
            ReservationPaymentStatus::Deposit => $profile->confirm_on_deposit,
            default => false,
        };
    }

    private function refreshGuest(Reservation $reservation): void
    {
        $guest = Guest::query()->whereKey($reservation->guest_id)->lockForUpdate()->firstOrFail();

        $guest->update([
            'full_name' => $reservation->guest_full_name,
            'phone' => $reservation->guest_phone,
            // An empty country on this booking does not erase one known from an earlier stay.
            'country' => $reservation->guest_country ?? $guest->country,
        ]);
    }
}
