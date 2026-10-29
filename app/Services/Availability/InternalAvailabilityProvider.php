<?php

namespace App\Services\Availability;

use App\Contracts\AvailabilityProvider;
use App\Enums\ReservationStatus;
use App\Models\AvailabilityBlock;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Availability from this application's own data.
 *
 * A unit is taken on a night when an availability block (manual, iCal or OTA) or an
 * awaiting_payment/confirmed reservation covers it. Pending requests never block.
 * Inactive units are never sold. A blocking reservation room without an assigned unit
 * still consumes one unit of its room type, so capacity is never oversold.
 *
 * This does not lock anything: callers that write a reservation must lock the unit rows
 * (lockForUpdate) inside their transaction before asking.
 */
class InternalAvailabilityProvider implements AvailabilityProvider
{
    public function isAvailable(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?Reservation $except = null,
    ): bool {
        return $this->availableUnits($roomType, $checkIn, $checkOut, $except)->isNotEmpty();
    }

    public function availableUnits(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?Reservation $except = null,
    ): Collection {
        [$units, $nights] = $this->occupancy($roomType, $checkIn, $checkOut, $except);

        $free = $units
            ->reject(fn (Unit $unit) => collect($nights)->contains(fn (array $night) => isset($night['occupied'][$unit->id])))
            ->values();

        $unassigned = max(array_column($nights, 'unassigned'));

        return $free->take(max(0, $free->count() - $unassigned));
    }

    public function blockedDates(RoomType $roomType, CarbonInterface $from, CarbonInterface $to): array
    {
        [$units, $nights] = $this->occupancy($roomType, $from, $to);

        $total = $units->count();

        return array_keys(array_filter(
            $nights,
            fn (array $night) => $total - count($night['occupied']) - $night['unassigned'] <= 0,
        ));
    }

    /**
     * Active units of the room type and, per night of [from, to), which of them are taken
     * plus how many blocking reservation rooms have no unit yet.
     *
     * @return array{EloquentCollection<int, Unit>, array<string, array{occupied: array<string, true>, unassigned: int}>}
     */
    private function occupancy(
        RoomType $roomType,
        CarbonInterface $from,
        CarbonInterface $to,
        ?Reservation $except = null,
    ): array {
        $start = $from->toDateString();
        $end = $to->toDateString();

        if ($end <= $start) {
            throw new InvalidArgumentException("End date {$end} must be after start date {$start}.");
        }

        $nights = [];
        foreach (CarbonPeriod::create($start, $end)->excludeEndDate() as $night) {
            $nights[$night->toDateString()] = ['occupied' => [], 'unassigned' => 0];
        }

        $units = $roomType->units()->active()->orderBy('code')->get();
        $unitIds = $units->modelKeys();

        // Ranges are raw Y-m-d strings straight from the database, clipped to the window.
        $mark = function (string $rangeStart, string $rangeEnd, ?string $unitId) use (&$nights, $start, $end): void {
            $period = CarbonPeriod::create(max($rangeStart, $start), min($rangeEnd, $end))->excludeEndDate();

            foreach ($period as $night) {
                $date = $night->toDateString();

                if ($unitId === null) {
                    $nights[$date]['unassigned']++;
                } else {
                    $nights[$date]['occupied'][$unitId] = true;
                }
            }
        };

        AvailabilityBlock::query()
            ->whereIn('unit_id', $unitIds)
            ->where('start_date', '<', $end)
            ->where('end_date', '>', $start)
            ->toBase()
            ->get(['unit_id', 'start_date', 'end_date'])
            ->each(fn (object $block) => $mark($block->start_date, $block->end_date, $block->unit_id));

        ReservationRoom::query()
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->where(fn (Builder $query) => $query
                ->whereIn('reservation_rooms.unit_id', $unitIds)
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('reservation_rooms.unit_id')
                    ->where('reservation_rooms.room_type_id', $roomType->id)))
            ->whereIn('reservations.status', ReservationStatus::blockingAvailability())
            ->where('reservations.check_in', '<', $end)
            ->where('reservations.check_out', '>', $start)
            ->when($except, fn (Builder $query, Reservation $except) => $query->where('reservations.id', '!=', $except->getKey()))
            ->toBase()
            ->get(['reservation_rooms.unit_id', 'reservations.check_in', 'reservations.check_out'])
            ->each(fn (object $room) => $mark($room->check_in, $room->check_out, $room->unit_id));

        return [$units, $nights];
    }
}
