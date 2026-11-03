<?php

namespace App\Actions\Availability;

use App\Enums\AvailabilityBlockSource;
use App\Exceptions\Availability\BlockNotAllowedException;
use App\Models\AvailabilityBlock;
use App\Models\PropertyProfile;
use App\Models\Unit;
use App\Services\Availability\UnitCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An admin closes nights of one unit by hand, e.g. for maintenance or an owner's stay.
 *
 * Only free nights can be blocked: a night already held by a reservation (awaiting_payment or
 * confirmed) or by another block of the unit is refused, so a guest's stay is never hidden
 * behind a block. The unit row is locked before any plain read, the same lock reservations
 * take (see ApproveReservation), so a block and an approval for the same nights cannot both
 * succeed.
 *
 * Dates are nights in the property timezone; lastNight is inclusive and stored as the
 * exclusive end_date (the day after).
 */
class CreateManualBlock
{
    public function __construct(private readonly UnitCalendar $calendar) {}

    /**
     * @throws BlockNotAllowedException when a night has passed or is already taken
     */
    public function handle(Unit $unit, CarbonInterface $firstNight, CarbonInterface $lastNight, ?string $reason = null): AvailabilityBlock
    {
        $start = $firstNight->toDateString();
        $end = $lastNight->toImmutable()->addDay()->toDateString();

        if ($lastNight->toDateString() < $start) {
            throw new InvalidArgumentException('The last night cannot be before the first night.');
        }

        if ($start < now(PropertyProfile::current()->timezone)->toDateString()) {
            throw BlockNotAllowedException::inThePast();
        }

        return DB::transaction(function () use ($unit, $start, $end, $reason): AvailabilityBlock {
            $locked = Unit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            $taken = array_keys(array_filter($this->calendar->forPeriod($locked, $start, $end)));

            if ($taken !== []) {
                throw BlockNotAllowedException::taken($locked, $taken);
            }

            $reason = trim((string) $reason);

            return $locked->availabilityBlocks()->create([
                'start_date' => $start,
                'end_date' => $end,
                'source' => AvailabilityBlockSource::Manual,
                'reason' => $reason === '' ? null : $reason,
            ]);
        });
    }
}
