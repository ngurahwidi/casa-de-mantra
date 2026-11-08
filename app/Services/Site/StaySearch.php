<?php

namespace App\Services\Site;

use App\Actions\Pricing\CalculatePrice;
use App\Contracts\AvailabilityProvider;
use App\Data\Pricing\PriceQuote;
use App\Data\Site\CalendarDay;
use App\Data\Site\CalendarMonth;
use App\Data\Site\StaySearchResult;
use App\Exceptions\Pricing\GuestCapacityExceededException;
use App\Exceptions\Pricing\PriceNotConfiguredException;
use App\Exceptions\Pricing\PricingException;
use App\Exceptions\Pricing\RoomTypeNotBookableException;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Pricing\NightlyRates;
use App\Services\Readiness\ReadinessCheck;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

/**
 * Date search for the public site: can this villa be booked for these dates, at what price,
 * and if not, which nearby dates can.
 *
 * Availability always comes from the AvailabilityProvider and prices from CalculatePrice, so the
 * widget agrees with what CreateReservationRequest accepts. A night is sellable when it is inside
 * the booking window, priced under the villa's rate plan and free on at least one unit.
 *
 * Dates are calendar dates in the property timezone; only the Y-m-d part is used and check-out
 * is exclusive.
 */
class StaySearch
{
    /** Nights that can be searched, counted from today; matches the nights the readiness check requires a price for. */
    public const NIGHTS_AHEAD = ReadinessCheck::PRICED_NIGHTS_AHEAD;

    /** Candidate stays fully priced and checked against units before the alternative search gives up. */
    private const ALTERNATIVE_ATTEMPTS = 20;

    public function __construct(
        private readonly AvailabilityProvider $availability,
        private readonly CalculatePrice $calculatePrice,
        private readonly NightlyRates $nightlyRates,
    ) {}

    /**
     * Today in the property timezone, at midnight.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(now(PropertyProfile::current()->timezone)->toDateString());
    }

    /**
     * The latest check-out date a guest can search for.
     */
    public function lastCheckOut(): CarbonImmutable
    {
        return $this->today()->addDays(self::NIGHTS_AHEAD);
    }

    /**
     * Prices the stay when it can be booked, or explains why not and suggests the nearest stay
     * of the same length that can.
     *
     * Guest capacity and an unbookable villa are not tied to the dates, so no alternative is
     * looked for in those cases.
     */
    public function search(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $adults,
        int $children = 0,
    ): StaySearchResult {
        $start = CarbonImmutable::parse($checkIn->toDateString());
        $end = CarbonImmutable::parse($checkOut->toDateString());

        if ($end <= $start) {
            throw new InvalidArgumentException("Check-out {$end->toDateString()} must be after check-in {$start->toDateString()}.");
        }

        if ($start < $this->today()) {
            return StaySearchResult::unavailable(DatesNotAvailableException::inThePast()->getMessage());
        }

        if ($end > $this->lastCheckOut()) {
            return StaySearchResult::unavailable('These dates are too far ahead to book yet.');
        }

        $ratePlan = $this->ratePlan($roomType);

        if ($ratePlan === null) {
            return StaySearchResult::unavailable(RoomTypeNotBookableException::unpublished()->getMessage());
        }

        $nights = (int) $start->diffInDays($end);

        try {
            $quote = $this->calculatePrice->handle($roomType, $ratePlan, $start, $end, $adults, $children);
        } catch (GuestCapacityExceededException|RoomTypeNotBookableException $exception) {
            return StaySearchResult::unavailable($exception->getMessage());
        } catch (PriceNotConfiguredException) {
            return StaySearchResult::unavailable(
                'Some of these nights are not open for booking yet.',
                $this->nearestAlternative($roomType, $ratePlan, $start, $nights, $adults, $children),
            );
        } catch (PricingException $exception) {
            return StaySearchResult::unavailable(
                $exception->getMessage(),
                $this->nearestAlternative($roomType, $ratePlan, $start, $nights, $adults, $children),
            );
        }

        if (! $this->availability->isAvailable($roomType, $start, $end)) {
            return StaySearchResult::unavailable(
                DatesNotAvailableException::taken()->getMessage(),
                $this->nearestAlternative($roomType, $ratePlan, $start, $nights, $adults, $children),
            );
        }

        return StaySearchResult::available($quote, $ratePlan->cancellationPolicy);
    }

    /**
     * Nights in [from, to) that cannot be sold: taken on every unit, without a price, outside
     * the booking window, or every night when the villa has no active rate plan.
     *
     * @return list<string> dates as Y-m-d, ascending
     */
    public function unsellableNights(RoomType $roomType, CarbonInterface $from, CarbonInterface $to): array
    {
        $all = [];

        foreach (CarbonPeriod::create($from->toDateString(), $to->toDateString())->excludeEndDate() as $night) {
            $all[] = $night->toDateString();
        }

        $windowStart = max($from->toDateString(), $this->today()->toDateString());
        $windowEnd = min($to->toDateString(), $this->lastCheckOut()->toDateString());
        $ratePlan = $this->ratePlan($roomType);

        if ($ratePlan === null || $windowEnd <= $windowStart) {
            return $all;
        }

        $sellable = array_diff(
            array_keys(array_filter($this->nightlyRates->forPeriod($ratePlan, $windowStart, $windowEnd))),
            $this->availability->blockedDates($roomType, CarbonImmutable::parse($windowStart), CarbonImmutable::parse($windowEnd)),
        );

        return array_values(array_diff($all, $sellable));
    }

    /**
     * Whether a stay can start on the date: its first night can be sold.
     */
    public function canCheckIn(RoomType $roomType, CarbonInterface $date): bool
    {
        return $this->unsellableNights($roomType, $date, $date->toImmutable()->addDay()) === [];
    }

    /**
     * The latest check-out date for a stay starting on checkIn: the first unsellable night on or
     * after it, since a guest may leave on the morning that night begins.
     *
     * @return string Y-m-d; checkIn itself when its own night cannot be sold
     */
    public function latestCheckOut(RoomType $roomType, CarbonInterface $checkIn): string
    {
        $unsellable = $this->unsellableNights($roomType, $checkIn, $this->lastCheckOut());

        return $unsellable[0] ?? $this->lastCheckOut()->toDateString();
    }

    /**
     * Calendar months starting at the given month, with each date marked past and sellable.
     *
     * @param  int  $count  number of months, at least 1
     * @return list<CalendarMonth>
     */
    public function calendar(RoomType $roomType, CarbonInterface $month, int $count = 1): array
    {
        $first = CarbonImmutable::parse($month->format('Y-m-01'));
        $end = $first->addMonths(max(1, $count));
        $today = $this->today()->toDateString();

        $unsellable = array_flip($this->unsellableNights($roomType, $first, $end));

        $months = [];

        for ($current = $first; $current < $end; $current = $current->addMonth()) {
            $days = [];

            foreach (CarbonPeriod::create($current, $current->endOfMonth()->startOfDay()) as $date) {
                $day = $date->toDateString();

                $days[] = new CalendarDay(
                    date: $day,
                    dayOfMonth: $date->day,
                    isPast: $day < $today,
                    isSellable: ! isset($unsellable[$day]),
                );
            }

            $months[] = new CalendarMonth(
                month: $current->format('Y-m'),
                label: $current->format('F Y'),
                leadingBlanks: $current->dayOfWeekIso - 1,
                days: $days,
            );
        }

        return $months;
    }

    /**
     * The bookable stay of the same length whose check-in is closest to the requested one.
     * Later dates win a tie. The search stays inside the booking window and stops after
     * ALTERNATIVE_ATTEMPTS fully checked candidates.
     */
    private function nearestAlternative(
        RoomType $roomType,
        RatePlan $ratePlan,
        CarbonImmutable $checkIn,
        int $nights,
        int $adults,
        int $children,
    ): ?PriceQuote {
        $today = $this->today();
        $lastCheckIn = $this->lastCheckOut()->subDays($nights);

        if ($lastCheckIn < $today) {
            return null;
        }

        $unsellable = array_flip($this->unsellableNights($roomType, $today, $this->lastCheckOut()));
        $attempts = 0;

        for ($offset = 1; $offset <= self::NIGHTS_AHEAD; $offset++) {
            foreach ([$checkIn->addDays($offset), $checkIn->subDays($offset)] as $candidate) {
                if ($candidate < $today || $candidate > $lastCheckIn) {
                    continue;
                }

                if (! $this->allNightsSellable($candidate, $nights, $unsellable)) {
                    continue;
                }

                if (++$attempts > self::ALTERNATIVE_ATTEMPTS) {
                    return null;
                }

                $candidateEnd = $candidate->addDays($nights);

                try {
                    $quote = $this->calculatePrice->handle($roomType, $ratePlan, $candidate, $candidateEnd, $adults, $children);
                } catch (PricingException) {
                    continue;
                }

                if ($this->availability->isAvailable($roomType, $candidate, $candidateEnd)) {
                    return $quote;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $unsellable  unsellable nights as Y-m-d keys
     */
    private function allNightsSellable(CarbonImmutable $checkIn, int $nights, array $unsellable): bool
    {
        for ($night = 0; $night < $nights; $night++) {
            if (isset($unsellable[$checkIn->addDays($night)->toDateString()])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rate plan sold on the public site, or null when the villa has none that is active.
     */
    private function ratePlan(RoomType $roomType): ?RatePlan
    {
        $ratePlan = $roomType->nonRefundableRatePlan()->with('cancellationPolicy')->first();

        return $ratePlan?->is_active ? $ratePlan : null;
    }
}
