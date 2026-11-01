<?php

namespace App\Enums;

/**
 * The kinds of price an admin sets on the Pricing page, stored in rate_rules.type.
 *
 * Each type owns a band of rate_rules.priority, and a higher priority wins on a night covered
 * by several rules. Because the bands do not overlap, special dates always beat a season, a
 * season beats the weekend price and every rule beats the base price. Within its band the
 * admin orders the rules of one type by dragging them; the numbers are never shown.
 */
enum RateRuleType: string
{
    case Base = 'base';
    case Weekend = 'weekend';
    case Seasonal = 'seasonal';
    case SpecialDates = 'special_dates';

    /** Rules of one type, per rate plan, that fit in a band. */
    public const BAND_SIZE = 1000;

    public function lowestPriority(): int
    {
        return match ($this) {
            self::Base => 0,
            self::Weekend => 1000,
            self::Seasonal => 2000,
            self::SpecialDates => 3000,
        };
    }

    public function highestPriority(): int
    {
        return $this === self::Base ? 0 : $this->lowestPriority() + self::BAND_SIZE - 1;
    }

    public function label(): string
    {
        return match ($this) {
            self::Base => 'Base price',
            self::Weekend => 'Weekend price',
            self::Seasonal => 'Seasonal price',
            self::SpecialDates => 'Special dates',
        };
    }
}
