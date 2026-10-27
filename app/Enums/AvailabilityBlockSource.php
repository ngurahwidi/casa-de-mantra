<?php

namespace App\Enums;

enum AvailabilityBlockSource: string
{
    case Manual = 'manual';
    case Ical = 'ical';
    case OtaManual = 'ota_manual';
}
