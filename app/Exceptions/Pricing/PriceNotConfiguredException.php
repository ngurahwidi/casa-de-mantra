<?php

namespace App\Exceptions\Pricing;

/**
 * One or more nights of the stay have no matching rate rule, so they cannot be sold.
 */
class PriceNotConfiguredException extends PricingException
{
    /**
     * @param  list<string>  $dates  nights without a price as Y-m-d, ascending
     */
    public function __construct(public readonly array $dates)
    {
        parent::__construct('No price is available for '.implode(', ', $dates).'.');
    }
}
