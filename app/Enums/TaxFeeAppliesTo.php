<?php

namespace App\Enums;

enum TaxFeeAppliesTo: string
{
    case PerNight = 'per_night';
    case PerBooking = 'per_booking';
    case PerGuest = 'per_guest';
}
