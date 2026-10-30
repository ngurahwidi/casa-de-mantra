<?php

namespace App\Actions\Reservation;

use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Exceptions\Reservation\StayNotFinishedException;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservation\ReservationStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Marks a confirmed stay as completed once its check-out date is before today in the property
 * timezone.
 *
 * handle() completes one reservation (the admin's "Mark as Completed"); completeCheckedOut()
 * completes every one that is due and is meant to run daily (T9.1) as a system action.
 */
class CompleteReservation
{
    public const NOTE = 'Stay ended.';

    public function __construct(private readonly ReservationStateMachine $stateMachine) {}

    /**
     * @param  User|null  $actor  the admin who completed it; null for system actions
     *
     * @throws InvalidStatusTransitionException when the reservation is not confirmed
     * @throws StayNotFinishedException when check-out is today or later
     */
    public function handle(Reservation $reservation, ?User $actor = null, ?string $note = null): Reservation
    {
        $today = self::today();

        return DB::transaction(function () use ($reservation, $actor, $note, $today): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReservationStatus::Confirmed) {
                throw new InvalidStatusTransitionException(
                    $locked->status,
                    ReservationStatus::Completed,
                    "Only confirmed reservations can be completed; this one is {$locked->status->value}.",
                );
            }

            if ($locked->check_out->toDateString() >= $today) {
                throw StayNotFinishedException::forCompletion($locked->check_out);
            }

            return $this->stateMachine->transition($reservation, ReservationStatus::Completed, $actor, $note);
        });
    }

    /**
     * Completes every confirmed reservation whose check-out date has passed, each in a
     * transaction of its own; one cancelled meanwhile is skipped.
     *
     * @return int the number of reservations completed
     */
    public function completeCheckedOut(): int
    {
        $today = self::today();
        $completed = 0;

        foreach ($this->due($today)->orderBy('check_out')->pluck('id') as $id) {
            $completed += (int) DB::transaction(function () use ($id, $today): bool {
                $reservation = $this->due($today)->whereKey($id)->lockForUpdate()->first();

                if ($reservation === null) {
                    return false;
                }

                $this->stateMachine->transition($reservation, ReservationStatus::Completed, null, self::NOTE);

                return true;
            });
        }

        return $completed;
    }

    /**
     * @return Builder<Reservation>
     */
    private function due(string $today): Builder
    {
        return Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->where('check_out', '<', $today);
    }

    private static function today(): string
    {
        return now(PropertyProfile::current()->timezone)->toDateString();
    }
}
