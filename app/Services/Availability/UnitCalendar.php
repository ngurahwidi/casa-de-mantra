<?php

namespace App\Services\Availability;

use App\Enums\ReservationStatus;
use App\Models\AvailabilityBlock;
use App\Models\Reservation;
use App\Models\Unit;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * What takes each night of one unit: its availability blocks (manual, iCal, OTA) and the
 * awaiting_payment/confirmed reservations assigned to it. Pending requests hold no unit.
 *
 * The admin availability calendar shows it, and CreateManualBlock uses it to refuse nights
 * that are already taken. Unlike AvailabilityProvider it looks at the unit alone, whatever its
 * status, so an inactive unit can still be blocked and shown.
 */
class UnitCalendar
{
    /**
     * @param  string  $start  first night as Y-m-d
     * @param  string  $end  Y-m-d, exclusive
     * @return array<string, list<Reservation|AvailabilityBlock>> every night of [start, end) as Y-m-d,
     *                                                            reservations first, then blocks by start date
     */
    public function forPeriod(Unit $unit, string $start, string $end): array
    {
        if ($end <= $start) {
            throw new InvalidArgumentException("End date {$end} must be after start date {$start}.");
        }

        $reservations = Reservation::query()
            ->whereHas('reservationRooms', fn (Builder $query) => $query->whereBelongsTo($unit))
            ->whereIn('status', ReservationStatus::blockingAvailability())
            ->where('check_in', '<', $end)
            ->where('check_out', '>', $start)
            ->orderBy('check_in')
            ->get();

        $blocks = $unit->availabilityBlocks()
            ->with('icalFeed')
            ->where('start_date', '<', $end)
            ->where('end_date', '>', $start)
            ->orderBy('start_date')
            ->get();

        $nights = [];

        foreach (CarbonPeriod::create($start, $end)->excludeEndDate() as $night) {
            $date = $night->toDateString();

            $nights[$date] = [
                ...$reservations->filter(fn (Reservation $reservation): bool => self::covers($reservation->check_in->toDateString(), $reservation->check_out->toDateString(), $date))->values()->all(),
                ...$blocks->filter(fn (AvailabilityBlock $block): bool => self::covers($block->start_date->toDateString(), $block->end_date->toDateString(), $date))->values()->all(),
            ];
        }

        return $nights;
    }

    /**
     * Whether the stay [from, to) includes the night of $date; the check-out day is not a night.
     */
    private static function covers(string $from, string $to, string $date): bool
    {
        return $from <= $date && $date < $to;
    }
}
