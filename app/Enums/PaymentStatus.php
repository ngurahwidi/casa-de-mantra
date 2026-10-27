<?php

namespace App\Enums;

/**
 * State of a single payment record (payments.status).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Expired = 'expired';
}
