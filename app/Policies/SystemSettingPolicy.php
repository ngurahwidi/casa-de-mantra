<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * System settings (property profile, taxes, cancellation policies, iCal feeds, notification
 * templates) and what is sold (room types, units, amenities): every admin may look at them,
 * only the owner may change them. Staff run the daily work: reservations, the calendar,
 * prices and content.
 *
 * Attached to each of these models with #[UsePolicy].
 */
class SystemSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $model): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->isOwner();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function reorder(User $user): bool
    {
        return $user->isOwner();
    }
}
