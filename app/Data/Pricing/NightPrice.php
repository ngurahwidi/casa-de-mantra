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
     */
    public function __construct(
        public string $date,
        public string $price,
        public string $rateRuleId,
    ) {}
}
