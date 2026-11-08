<?php

namespace App\Data\Site;

/**
 * A month of the public availability calendar, laid out from Monday.
 */
final readonly class CalendarMonth
{
    /**
     * @param  string  $month  Y-m
     * @param  string  $label  e.g. "November 2026"
     * @param  int  $leadingBlanks  empty cells before the 1st in a Monday-first week, 0–6
     * @param  list<CalendarDay>  $days  every date of the month, ascending
     */
    public function __construct(
        public string $month,
        public string $label,
        public int $leadingBlanks,
        public array $days,
    ) {}
}
