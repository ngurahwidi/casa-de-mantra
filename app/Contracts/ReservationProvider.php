<?php

namespace App\Contracts;

use App\Data\Reservation\ReservationRequestData;
use App\Exceptions\Pricing\PricingException;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Models\Reservation;

/**
 * Takes booking requests from the public site.
 *
 * The internal implementation stores them in this application's own tables; a PMS or
 * channel manager adapter can replace it without touching the site.
 */
interface ReservationProvider
{
    /**
     * Records a request-to-book. The stay is priced and checked for availability, but its
     * dates are not held until an admin approves it.
     *
     * @throws PricingException when the stay cannot be sold under the rate plan
     * @throws DatesNotAvailableException when the dates are taken or already past
     */
    public function createRequest(ReservationRequestData $data): Reservation;
}
