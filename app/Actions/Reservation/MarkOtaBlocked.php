<?php

namespace App\Actions\Reservation;

use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\OtaBlockNotAllowedException;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * The "Blocked on OTA" checklist: an admin notes that the reservation's dates have been closed by
 * hand on the OTA calendars (Booking.com, Expedia), or clears that note again.
 *
 * Only a reservation that holds its dates (awaiting_payment or confirmed) can be marked. Marking
 * it again keeps the first ota_blocked_at. The mark can be cleared in any status, e.g. after a
 * cancellation once the dates are open again on the OTAs.
 *
 * This is a note for the admin, not a status change, so nothing is logged or sent to the outbox.
 */
class MarkOtaBlocked
{
    /**
     * @param  bool  $blocked  false clears the mark
     *
     * @throws OtaBlockNotAllowedException when marking a reservation that does not hold its dates
     */
    public function handle(Reservation $reservation, bool $blocked = true): Reservation
    {
        return DB::transaction(function () use ($reservation, $blocked): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($blocked && ! in_array($locked->status, ReservationStatus::blockingAvailability(), true)) {
                throw OtaBlockNotAllowedException::forStatus($locked->status);
            }

            $locked->ota_blocked_at = $blocked ? ($locked->ota_blocked_at ?? now()) : null;
            $locked->save();

            $reservation->ota_blocked_at = $locked->ota_blocked_at;
            $reservation->syncOriginalAttribute('ota_blocked_at');

            return $reservation;
        });
    }
}
