<?php

namespace App\Exceptions\Reservation;

/**
 * An amount entered by an admin is not a valid price.
 */
class InvalidAmountException extends ReservationException
{
    public static function forTotal(string $amount): self
    {
        return new self("The total must be a positive number with at most two decimals; got \"{$amount}\".");
    }
}
