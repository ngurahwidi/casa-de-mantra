<?php

namespace App\Services\Reservation;

use App\Contracts\AvailabilityProvider;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gives every room of a reservation a free unit of its room type, for the actions that make a
 * reservation hold its dates (ApproveReservation, ConfirmReservation, ReactivateReservation).
 *
 * The reservation's rooms and every unit of their room types are locked, in that order, so
 * concurrent callers cannot deadlock and a second one waits until the first commits. Call it
 * inside a transaction, right after locking the reservation row and before any plain read:
 * see ApproveReservation for why the order matters under REPEATABLE READ.
 *
 * A room keeps its unit while that unit is still free, otherwise it moves to another free unit
 * of its room type. Rooms of the same type in one reservation each get their own unit.
 */
class UnitAllocator
{
    public function __construct(private readonly AvailabilityProvider $availability) {}

    /**
     * @return Collection<int, ReservationRoom> the reservation's rooms with their new units
     *
     * @throws DatesNotAvailableException when a room has no free unit left
     */
    public function allocate(Reservation $reservation): Collection
    {
        $rooms = ReservationRoom::query()
            ->whereBelongsTo($reservation)
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
                ->availableUnits($room->roomType()->firstOrFail(), $reservation->check_in, $reservation->check_out, $reservation)
                ->reject(fn (Unit $unit) => in_array($unit->id, $assigned, true));

            $unit = $free->firstWhere('id', $room->unit_id) ?? $free->first();

            if ($unit === null) {
                throw DatesNotAvailableException::taken();
            }

            $assigned[] = $unit->id;
            $room->update(['unit_id' => $unit->id]);
        }

        return $rooms;
    }
}
