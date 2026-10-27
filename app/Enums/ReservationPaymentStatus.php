<?php

namespace App\Enums;

/**
 * Aggregate payment state of a reservation (reservations.payment_status).
 * For the state of a single payment record see PaymentStatus.
 */
enum ReservationPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Deposit = 'deposit';
    case Paid = 'paid';
    case Refunded = 'refunded';
}
