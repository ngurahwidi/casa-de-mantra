<?php

namespace App\Enums;

enum AvailabilityBlockSource: string
{
    case Manual = 'manual';
    case OtaManual = 'ota_manual';
}
