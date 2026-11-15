<?php

namespace App\Policies;

use App\Models\ReservationSource;
use App\Models\User;

/**
 * Reservation sources are part of the daily work: every admin, staff included, may add one and
 * change or deactivate it. The built-in website source cannot be changed, and a source cannot
 * be deleted once a reservation has it.
 */
class ReservationSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReservationSource $reservationSource): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ReservationSource $reservationSource): bool
    {
        return ! $reservationSource->isWebsite();
    }

    public function delete(User $user, ReservationSource $reservationSource): bool
    {
        return $reservationSource->canBeDeleted();
    }

    /**
     * Sources are deleted one at a time, so each is checked for reservations.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }
}
