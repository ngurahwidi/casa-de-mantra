<?php

namespace App\Enums;

enum IcalChannel: string
{
    case Booking = 'booking';
    case Expedia = 'expedia';
    case Airbnb = 'airbnb';
    case Other = 'other';
}
