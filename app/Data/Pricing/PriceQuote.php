<?php

namespace App\Data\Pricing;

/**
 * The price of a stay in the property currency.
 *
 * Amounts are decimals with two places, ready for decimal(14,2) columns.
 * total = subtotal + taxTotal; taxes included in the nightly price are not added again.
 */
final readonly class PriceQuote
{
    /**
     * @param  string  $checkIn  Y-m-d
     * @param  string  $checkOut  Y-m-d, exclusive
     * @param  list<NightPrice>  $nights  ascending by date
     */
    public function __construct(
        public string $checkIn,
        public string $checkOut,
        public array $nights,
        public string $subtotal,
        public string $taxTotal,
        public string $total,
    ) {}

    public function nightCount(): int
    {
        return count($this->nights);
    }
}
