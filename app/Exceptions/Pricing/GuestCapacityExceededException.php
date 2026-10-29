<?php

namespace App\Exceptions\Pricing;

use App\Models\RoomType;

class GuestCapacityExceededException extends PricingException
{
    public function __construct(public readonly int $maxAdults, public readonly int $maxChildren)
    {
        parent::__construct("This stay allows up to {$maxAdults} adults and {$maxChildren} children.");
    }

    public static function for(RoomType $roomType): self
    {
        return new self($roomType->max_adults, $roomType->max_children);
    }
}
