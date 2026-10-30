<?php

namespace App\Actions\Reservation;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Reservation\ReservationStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Expires reservations whose payment window has ended without any payment, releasing their
 * dates. Meant to run on a schedule (T9.1) as a system action.
 *
 * Only unpaid reservations expire. One that received a deposit, or was paid in full but could
 * not be confirmed, keeps its dates for an admin to settle: RecordPayment no longer accepts
 * payments once a reservation is expired.
 *
 * Each reservation is expired in a transaction of its own, after locking it and checking again
 * that it is still due, so a payment or cancellation recorded meanwhile wins. Its rooms keep
 * their unit, which ReactivateReservation tries first.
 */
class ExpireStaleReservations
{
    public const NOTE = 'Payment window ended without payment.';

    public function __construct(private readonly ReservationStateMachine $stateMachine) {}

    /**
     * @return int the number of reservations expired
     */
    public function handle(): int
    {
        $expired = 0;

        foreach ($this->due()->orderBy('expires_at')->pluck('id') as $id) {
            $expired += (int) DB::transaction(function () use ($id): bool {
                $reservation = $this->due()->whereKey($id)->lockForUpdate()->first();

                if ($reservation === null) {
                    return false;
                }

                $this->stateMachine->transition($reservation, ReservationStatus::Expired, null, self::NOTE);

                return true;
            });
        }

        return $expired;
    }

    /**
     * @return Builder<Reservation>
     */
    private function due(): Builder
    {
        return Reservation::query()
            ->where('status', ReservationStatus::AwaitingPayment)
            ->where('payment_status', ReservationPaymentStatus::Unpaid)
            ->where('expires_at', '<', now());
    }
}
