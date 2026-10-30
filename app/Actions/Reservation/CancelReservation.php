<?php

namespace App\Actions\Reservation;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Exceptions\Reservation\ReasonRequiredException;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservation\ReservationStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a pending, awaiting_payment or confirmed reservation.
 *
 * The reason is the note of the status log entry. Once cancelled the reservation no longer
 * holds its dates; its rooms keep their unit for the record. The rate is non-refundable, so
 * nothing is refunded and payment_status stays as it is. When money was received, the log
 * note says so; an exceptional refund is recorded by hand.
 */
class CancelReservation
{
    public const NO_REFUND_NOTE = 'No refund: the rate is non-refundable.';

    public function __construct(private readonly ReservationStateMachine $stateMachine) {}

    /**
     * @param  User|null  $actor  the admin who cancelled it; null for system actions
     *
     * @throws ReasonRequiredException when the reason is blank
     * @throws InvalidStatusTransitionException when the reservation can no longer be cancelled
     */
    public function handle(Reservation $reservation, ?User $actor, string $reason): Reservation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ReasonRequiredException::forCancellation();
        }

        return DB::transaction(function () use ($reservation, $actor, $reason): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            $note = $locked->payment_status === ReservationPaymentStatus::Unpaid
                ? $reason
                : $reason."\n".self::NO_REFUND_NOTE;

            return $this->stateMachine->transition($reservation, ReservationStatus::Cancelled, $actor, $note);
        });
    }
}
