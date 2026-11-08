<?php

namespace App\Data\Site;

/**
 * One date on the public availability calendar.
 *
 * A date is sellable when its night (the date up to the next morning) can be booked:
 * not in the past, inside the booking window, priced and free on at least one unit.
 * An unsellable date can still be a check-out day, because check-out is exclusive.
 */
final readonly class CalendarDay
{
    /**
     * @param  string  $date  Y-m-d
     */
    public function __construct(
        public string $date,
        public int $dayOfMonth,
        public bool $isPast,
        public bool $isSellable,
    ) {}
}
