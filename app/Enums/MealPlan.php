<?php

namespace App\Enums;

enum MealPlan: string
{
    case RoomOnly = 'room_only';
    case Breakfast = 'breakfast';
    case HalfBoard = 'half_board';
    case FullBoard = 'full_board';
}
