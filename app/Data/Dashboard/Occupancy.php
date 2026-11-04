<?php

namespace App\Data\Dashboard;

/**
 * How many unit-nights of a month are sold, out of the nights the active units can be sold.
 */
final readonly class Occupancy
{
    /**
     * @param  string  $month  first day of the month, Y-m-d
     */
    public function __construct(
        public string $month,
        public int $soldNights,
        public int $availableNights,
    ) {}

    /**
     * Rounded to a whole percent; null when there is nothing to sell.
     */
    public function percentage(): ?int
    {
        return $this->availableNights === 0 ? null : (int) round($this->soldNights * 100 / $this->availableNights);
    }
}
