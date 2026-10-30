<?php

namespace App\Services\Reservation;

use App\Actions\Reservation\CreateReservationRequest;
use App\Contracts\ReservationProvider;
use App\Data\Reservation\ReservationRequestData;
use App\Models\Reservation;

/**
 * Reservations stored in this application's own tables.
 */
class InternalReservationProvider implements ReservationProvider
{
    public function __construct(private readonly CreateReservationRequest $createRequest) {}

    public function createRequest(ReservationRequestData $data): Reservation
    {
        return $this->createRequest->handle($data);
    }
}
