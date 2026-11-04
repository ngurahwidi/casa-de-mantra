<?php

namespace App\Enums;

enum ReservationSource: string
{
    case DirectWeb = 'direct_web';
    case BookingCom = 'booking_com';
    case Expedia = 'expedia';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::DirectWeb => 'Website',
            self::BookingCom => 'Booking.com',
            self::Expedia => 'Expedia',
            self::Manual => 'Phone / direct',
        };
    }
}
