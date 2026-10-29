<?php

namespace App\Contracts;

use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Answers whether a room type can be sold for a stay.
 *
 * Dates are calendar dates in the property timezone; only the Y-m-d part is used.
 * Stays are half-open [checkIn, checkOut): the check-out day is not a night, so a
 * stay may start on the day another one ends.
 */
interface AvailabilityProvider
{
    /**
     * Whether at least one unit of the room type is free for every night of the stay.
     *
     * @param  Reservation|null  $except  reservation to ignore, e.g. when re-validating its own dates
     */
    public function isAvailable(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?Reservation $except = null,
    ): bool;

    /**
     * Units of the room type that are free for every night of the stay, ordered by code.
     *
     * @param  Reservation|null  $except  reservation to ignore, e.g. when re-validating its own dates
     * @return Collection<int, Unit>
     */
    public function availableUnits(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?Reservation $except = null,
    ): Collection;

    /**
     * Nights in [from, to) on which no unit of the room type can be sold.
     *
     * @return list<string> dates as Y-m-d, ascending
     */
    public function blockedDates(RoomType $roomType, CarbonInterface $from, CarbonInterface $to): array;
}
