<?php

namespace App\Data\Reservation;

use App\Models\RatePlan;
use App\Models\RoomType;
use Carbon\CarbonInterface;

/**
 * A guest's request to book one room type for a stay.
 *
 * Dates are calendar dates in the property timezone; only the Y-m-d part is used.
 * check_out is exclusive.
 */
final readonly class ReservationRequestData
{
    public function __construct(
        public RoomType $roomType,
        public RatePlan $ratePlan,
        public CarbonInterface $checkIn,
        public CarbonInterface $checkOut,
        public int $adults,
        public int $children,
        public GuestDetails $guest,
        public ?string $specialRequests = null,
    ) {}
}
