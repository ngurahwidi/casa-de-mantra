<?php

namespace App\Data\Pricing;

use App\Enums\RateRuleType;
use App\Models\RateRule;
use Carbon\CarbonInterface;

/**
 * A weekend, seasonal or special-dates price as entered on the Pricing page.
 *
 * Dates are nights in the property timezone; only the Y-m-d part is used and lastNight is
 * inclusive (the night before check-out). The base price is set with SetBasePrice instead.
 */
final readonly class PriceRuleData
{
    /**
     * @param  string  $pricePerNight  non-negative decimal, e.g. "1500000" or "1500000.50"
     */
    private function __construct(
        public RateRuleType $type,
        public string $name,
        public string $pricePerNight,
        public int $daysOfWeekMask = RateRule::ALL_DAYS,
        public ?CarbonInterface $firstNight = null,
        public ?CarbonInterface $lastNight = null,
        public ?int $minStay = null,
    ) {}

    /**
     * A price for some days of every week, e.g. Friday and Saturday nights, from today on.
     *
     * @param  iterable<int|string>  $isoDays  1 = Monday ... 7 = Sunday
     */
    public static function weekend(string $name, string $pricePerNight, iterable $isoDays): self
    {
        return new self(RateRuleType::Weekend, $name, $pricePerNight, RateRule::maskFor($isoDays));
    }

    /**
     * A price for every night of a period, e.g. high season, with an optional minimum stay.
     */
    public static function seasonal(string $name, string $pricePerNight, CarbonInterface $firstNight, CarbonInterface $lastNight, ?int $minStay = null): self
    {
        return new self(RateRuleType::Seasonal, $name, $pricePerNight, RateRule::ALL_DAYS, $firstNight, $lastNight, $minStay);
    }

    /**
     * A price for one night or a few nights in a row, e.g. Christmas, with an optional minimum stay.
     */
    public static function specialDates(string $name, string $pricePerNight, CarbonInterface $firstNight, ?CarbonInterface $lastNight = null, ?int $minStay = null): self
    {
        return new self(RateRuleType::SpecialDates, $name, $pricePerNight, RateRule::ALL_DAYS, $firstNight, $lastNight ?? $firstNight, $minStay);
    }
}
