<?php

namespace App\Events;

use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reservation was created or moved to another status; a null from_status on the log means created.
 *
 * Dispatched only after the transaction commits, so a rolled-back change notifies nobody.
 */
class ReservationStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly ReservationStatusLog $statusLog,
    ) {}
}
