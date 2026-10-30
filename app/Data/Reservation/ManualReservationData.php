<?php

namespace App\Data\Reservation;

use App\Enums\ReservationSource;
use App\Models\RatePlan;
use App\Models\RoomType;
use Carbon\CarbonInterface;

/**
 * A booking an admin enters by hand: taken over the phone, or made on an OTA.
 *
 * Dates are calendar dates in the property timezone; only the Y-m-d part is used.
 * check_out is exclusive.
 */
final readonly class ManualReservationData
{
    /**
     * @param  ReservationSource  $source  booking_com, expedia or manual
     * @param  string|null  $total  the amount the guest pays, e.g. the price on the OTA, as a decimal
     *                              with at most two places; null prices the stay from the rate rules
     * @param  string|null  $externalId  the booking number on the OTA, if any
     */
    public function __construct(
        public ReservationSource $source,
        public RoomType $roomType,
        public RatePlan $ratePlan,
        public CarbonInterface $checkIn,
        public CarbonInterface $checkOut,
        public int $adults,
        public int $children,
        public GuestDetails $guest,
        public ?string $total = null,
        public ?string $externalId = null,
        public ?string $specialRequests = null,
    ) {}
}
