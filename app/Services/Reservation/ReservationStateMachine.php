<?php

namespace App\Services\Reservation;

use App\Enums\OutboxEventStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes a reservation's status.
 *
 * pending → awaiting_payment → confirmed → completed, with the branches
 * pending → rejected, pending|awaiting_payment|confirmed → cancelled and awaiting_payment → expired.
 * An expired reservation can be reactivated back to awaiting_payment (T4.7). Cancelling a
 * confirmed (paid) reservation does not refund anything; the rate is non-refundable.
 *
 * Every transition writes a reservation_status_logs entry and a `reservation.<to_status>`
 * outbox event in the same transaction as the status change. The reservation row is locked
 * and its status re-read from the database, so a stale model or two concurrent transitions
 * cannot both pass validation.
 *
 * This only enforces the order of statuses. Preconditions such as availability or payment
 * are checked by the action that asks for the transition, inside the same transaction.
 */
class ReservationStateMachine
{
    /**
     * @var array<string, list<ReservationStatus>>
     */
    private const TRANSITIONS = [
        ReservationStatus::Pending->value => [
            ReservationStatus::AwaitingPayment,
            ReservationStatus::Rejected,
            ReservationStatus::Cancelled,
        ],
        ReservationStatus::AwaitingPayment->value => [
            ReservationStatus::Confirmed,
            ReservationStatus::Cancelled,
            ReservationStatus::Expired,
        ],
        ReservationStatus::Confirmed->value => [
            ReservationStatus::Completed,
            ReservationStatus::Cancelled,
        ],
        ReservationStatus::Expired->value => [
            ReservationStatus::AwaitingPayment,
        ],
        ReservationStatus::Completed->value => [],
        ReservationStatus::Rejected->value => [],
        ReservationStatus::Cancelled->value => [],
    ];

    /**
     * Moves the reservation to `$to` and updates the given model in place.
     *
     * Other attributes changed on the model beforehand (e.g. expires_at, reject_reason) are
     * saved together with the status.
     *
     * @param  User|null  $actor  the admin who made the change; null for system actions
     *
     * @throws InvalidStatusTransitionException
     */
    public function transition(
        Reservation $reservation,
        ReservationStatus $to,
        ?User $actor = null,
        ?string $note = null,
    ): Reservation {
        return DB::transaction(function () use ($reservation, $to, $actor, $note): Reservation {
            /** @var ReservationStatus $from */
            $from = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->valueOrFail('status');

            if (! $this->canTransition($from, $to)) {
                throw new InvalidStatusTransitionException($from, $to);
            }

            $reservation->status = $to;
            $reservation->save();

            ReservationStatusLog::create([
                'reservation_id' => $reservation->getKey(),
                'admin_user_id' => $actor?->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
            ]);

            OutboxEvent::create([
                'type' => "reservation.{$to->value}",
                'payload' => [
                    'reservation_id' => $reservation->getKey(),
                    'code' => $reservation->code,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'admin_user_id' => $actor?->getKey(),
                    'note' => $note,
                ],
                'status' => OutboxEventStatus::Pending,
            ]);

            return $reservation;
        });
    }

    public function canTransition(ReservationStatus $from, ReservationStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /**
     * Statuses reachable from `$from` in one step, e.g. to decide which admin actions to show.
     *
     * @return list<ReservationStatus>
     */
    public function allowedTransitions(ReservationStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }
}
