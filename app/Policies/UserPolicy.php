<?php

namespace App\Policies;

use App\Models\User;

/**
 * Admin accounts are managed by the owner only; staff do not see them.
 *
 * An owner cannot delete their own account, so the panel always keeps the owner who is signed in.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isOwner() && ! $user->is($model);
    }

    /**
     * Accounts are deleted one at a time, so the own-account check above always applies.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }
}
