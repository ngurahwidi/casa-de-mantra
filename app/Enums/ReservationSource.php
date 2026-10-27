<?php

namespace App\Enums;

enum ReservationSource: string
{
    case DirectWeb = 'direct_web';
    case BookingCom = 'booking_com';
    case Expedia = 'expedia';
    case Manual = 'manual';
}
