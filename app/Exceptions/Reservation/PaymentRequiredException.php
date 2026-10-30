<?php

namespace App\Exceptions\Reservation;

use App\Enums\ReservationPaymentStatus;

/**
 * The reservation has not been paid enough to be confirmed.
 */
class PaymentRequiredException extends ReservationException
{
    public static function forConfirmation(ReservationPaymentStatus $paymentStatus): self
    {
        return new self("A reservation can only be confirmed once it is paid; its payment status is {$paymentStatus->value}.");
    }
}
