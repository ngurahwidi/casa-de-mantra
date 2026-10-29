<?php

namespace App\Exceptions\Pricing;

/**
 * The room type is not published or its rate plan is not active.
 */
class RoomTypeNotBookableException extends PricingException
{
    public static function unpublished(): self
    {
        return new self('This accommodation is not available for booking.');
    }

    public static function inactiveRatePlan(): self
    {
        return new self('This rate is not available for booking.');
    }
}
