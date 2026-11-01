<?php

namespace App\Enums;

/**
 * The kinds of price an admin sets on the Pricing page.
 *
 * The type is not stored: it is encoded in rate_rules.priority, which the admin never enters.
 * A higher priority wins on a night covered by several rules, so special dates beat a season,
 * a season beats the weekend price and every rule beats the base price.
 */
enum RateRuleType: string
{
    case Base = 'base';
    case Weekend = 'weekend';
    case Seasonal = 'seasonal';
    case SpecialDates = 'special_dates';

    public function priority(): int
    {
        return match ($this) {
            self::Base => 0,
            self::Weekend => 10,
            self::Seasonal => 20,
            self::SpecialDates => 30,
        };
    }

    /**
     * The type a rule was created as, or null for a priority the Pricing page does not set.
     */
    public static function fromPriority(int $priority): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->priority() === $priority) {
                return $type;
            }
        }

        return null;
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
