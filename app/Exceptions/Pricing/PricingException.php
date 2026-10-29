<?php

namespace App\Exceptions\Pricing;

use DomainException;

/**
 * A stay that cannot be priced or sold under the requested room type and rate plan.
 *
 * Messages are written for guests and may be shown on the public site.
 */
abstract class PricingException extends DomainException
{
    //
}
