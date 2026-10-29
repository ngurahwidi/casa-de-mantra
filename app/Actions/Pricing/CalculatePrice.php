<?php

namespace App\Actions\Pricing;

use App\Data\Pricing\NightPrice;
use App\Data\Pricing\PriceQuote;
use App\Enums\TaxFeeAppliesTo;
use App\Enums\TaxFeeType;
use App\Exceptions\Pricing\GuestCapacityExceededException;
use App\Exceptions\Pricing\PriceNotConfiguredException;
use App\Exceptions\Pricing\RoomTypeNotBookableException;
use App\Exceptions\Pricing\StayLengthNotAllowedException;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\TaxFee;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Prices a stay under a rate plan.
 *
 * Each night of [checkIn, checkOut) takes the highest-priority rate rule whose date range
 * and days of week cover it; on equal priority the rule starting latest wins, then the
 * newest. A night without a rule cannot be sold. The minimum stay is the strictest of
 * the matched rules' min_stay_override, falling back to the rate plan's min_stay.
 *
 * Only active tax_fees count. Taxes included in the price are not added on top, so
 * tax_total holds only the taxes charged in addition to the subtotal.
 *
 * Dates are calendar dates in the property timezone; only the Y-m-d part is used.
 * Amounts are computed in cents to avoid floating point rounding.
 */
class CalculatePrice
{
    /**
     * @throws RoomTypeNotBookableException
     * @throws GuestCapacityExceededException
     * @throws PriceNotConfiguredException
     * @throws StayLengthNotAllowedException
     */
    public function handle(
        RoomType $roomType,
        RatePlan $ratePlan,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $adults,
        int $children = 0,
    ): PriceQuote {
        $start = $checkIn->toDateString();
        $end = $checkOut->toDateString();

        if ($end <= $start) {
            throw new InvalidArgumentException("Check-out {$end} must be after check-in {$start}.");
        }

        if ($adults < 1 || $children < 0) {
            throw new InvalidArgumentException('A stay needs at least one adult and cannot have negative children.');
        }

        if ($ratePlan->room_type_id !== $roomType->id) {
            throw new InvalidArgumentException("Rate plan {$ratePlan->id} does not belong to room type {$roomType->id}.");
        }

        if (! $roomType->is_published) {
            throw RoomTypeNotBookableException::unpublished();
        }

        if (! $ratePlan->is_active) {
            throw RoomTypeNotBookableException::inactiveRatePlan();
        }

        if ($adults > $roomType->max_adults || $children > $roomType->max_children) {
            throw GuestCapacityExceededException::for($roomType);
        }

        $rules = $ratePlan->rateRules()
            ->where('start_date', '<', $end)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $start))
            ->orderByDesc('priority')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $nights = [];
        $missing = [];
        $minStay = 1;

        foreach (CarbonPeriod::create($start, $end)->excludeEndDate() as $night) {
            $date = $night->toDateString();
            $day = 1 << ($night->dayOfWeekIso - 1);

            $rule = $rules->first(fn (RateRule $rule) => $rule->start_date->toDateString() <= $date
                && ($rule->end_date === null || $rule->end_date->toDateString() >= $date)
                && ($rule->days_of_week_mask & $day) !== 0);

            if ($rule === null) {
                $missing[] = $date;

                continue;
            }

            $nights[] = new NightPrice($date, $rule->price_per_night, $rule->id);
            $minStay = max($minStay, $rule->min_stay_override ?? $ratePlan->min_stay);
        }

        if ($missing !== []) {
            throw new PriceNotConfiguredException($missing);
        }

        $count = count($nights);

        if ($count < $minStay || ($ratePlan->max_stay !== null && $count > $ratePlan->max_stay)) {
            throw new StayLengthNotAllowedException($count, $minStay, $ratePlan->max_stay);
        }

        $nightCents = array_map(fn (NightPrice $night) => self::toCents($night->price), $nights);
        $subtotal = array_sum($nightCents);
        $taxTotal = $this->taxTotal($nightCents, $adults + $children);

        return new PriceQuote(
            checkIn: $start,
            checkOut: $end,
            nights: $nights,
            subtotal: self::format($subtotal),
            taxTotal: self::format($taxTotal),
            total: self::format($subtotal + $taxTotal),
        );
    }

    /**
     * Taxes charged on top of the nightly prices, in cents.
     *
     * Percent taxes apply to the room price (per night when applies_to is per_night, so
     * each night is rounded on its own); fixed taxes are multiplied by nights, guests or once.
     *
     * @param  list<int>  $nightCents
     */
    private function taxTotal(array $nightCents, int $guests): int
    {
        $total = 0;

        $taxes = TaxFee::query()->active()->where('is_included_in_price', false)->get();

        foreach ($taxes as $tax) {
            $value = self::toCents($tax->value);

            $total += match ($tax->type) {
                TaxFeeType::Percent => $tax->applies_to === TaxFeeAppliesTo::PerNight
                    ? array_sum(array_map(fn (int $night) => self::percentOf($night, $value), $nightCents))
                    : self::percentOf(array_sum($nightCents), $value),
                TaxFeeType::Fixed => $value * match ($tax->applies_to) {
                    TaxFeeAppliesTo::PerNight => count($nightCents),
                    TaxFeeAppliesTo::PerBooking => 1,
                    TaxFeeAppliesTo::PerGuest => $guests,
                },
            };
        }

        return $total;
    }

    /**
     * @param  int  $basisPoints  percent × 100, e.g. 1050 for 10.5%
     */
    private static function percentOf(int $cents, int $basisPoints): int
    {
        // Rounds half up; amounts are never negative.
        return intdiv($cents * $basisPoints + 5000, 10000);
    }

    /**
     * Converts a non-negative decimal string such as "1500000.50" to cents without floats.
     */
    private static function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
