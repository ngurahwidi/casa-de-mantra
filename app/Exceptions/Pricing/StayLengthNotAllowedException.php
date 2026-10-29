<?php

namespace App\Exceptions\Pricing;

class StayLengthNotAllowedException extends PricingException
{
    public function __construct(
        public readonly int $nights,
        public readonly int $minStay,
        public readonly ?int $maxStay,
    ) {
        parent::__construct($nights < $minStay
            ? "These dates require a minimum stay of {$minStay} nights."
            : "These dates allow a maximum stay of {$maxStay} nights.");
    }
}
