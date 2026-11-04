<?php

namespace App\Services\Reservation;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Other pending requests that want the same room type on at least one of the same nights.
 *
 * Pending requests hold no unit, so several can ask for the same nights. The admin reservation
 * screen warns about them: once one is approved, the others may no longer fit. This only looks
 * at the dates and room types; whether a unit is still free is checked when approving.
 */
class OverlappingRequests
{
    /**
     * @return Collection<int, Reservation> by check-in, then by when they were requested
     */
    public function for(Reservation $reservation): Collection
    {
        $roomTypeIds = $reservation->reservationRooms()->pluck('room_type_id')->unique();

        return Reservation::query()
            ->whereKeyNot($reservation->getKey())
            ->where('status', ReservationStatus::Pending)
            ->where('check_in', '<', $reservation->check_out->toDateString())
            ->where('check_out', '>', $reservation->check_in->toDateString())
            ->whereHas('reservationRooms', fn (Builder $query) => $query->whereIn('room_type_id', $roomTypeIds))
            ->orderBy('check_in')
            ->orderBy('created_at')
            ->get();
    }
}
