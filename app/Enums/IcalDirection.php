<?php

namespace App\Enums;

enum IcalDirection: string
{
    case Import = 'import';
    case Export = 'export';
}
