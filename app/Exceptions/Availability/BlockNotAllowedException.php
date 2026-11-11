<?php

namespace App\Exceptions\Availability;

use App\Models\Unit;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * An availability block cannot be added. Messages are written for admins.
 */
class BlockNotAllowedException extends DomainException
{
    /**
     * @param  list<string>  $nights  Y-m-d, ascending
     */
    public static function taken(Unit $unit, array $nights): self
    {
        $first = CarbonImmutable::parse($nights[0])->format('j M Y');

        return new self(count($nights) === 1
            ? "Unit {$unit->code} is already booked or blocked on the night of {$first}."
            : "Unit {$unit->code} is already booked or blocked on ".count($nights)." of these nights, starting {$first}.");
    }

    public static function inThePast(): self
    {
        return new self('A block cannot start before today.');
    }
}
