<?php

namespace App\Data\Pricing;

/**
 * The price of one night of a stay and the rate rule it came from.
 */
final readonly class NightPrice
{
    /**
     * @param  string  $date  the night as Y-m-d
     * @param  string  $price  decimal with two places, e.g. "1500000.00"
     * @param  string|null  $rateRuleId  null when the price was entered by an admin (CreateManualReservation)
     */
    public function __construct(
        public string $date,
        public string $price,
        public ?string $rateRuleId,
    ) {}
}
