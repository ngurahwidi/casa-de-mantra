<?php

namespace App\Enums;

enum TaxFeeType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
