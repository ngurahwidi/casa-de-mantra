<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Statuses that hold their dates. Pending requests do not block public availability.
     *
     * @return list<self>
     */
    public static function blockingAvailability(): array
    {
        return [self::AwaitingPayment, self::Confirmed];
    }
}
