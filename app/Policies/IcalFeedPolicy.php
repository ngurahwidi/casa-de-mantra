<?php

namespace App\Policies;

use App\Models\IcalFeed;
use App\Models\User;

/**
 * iCal feeds are a system setting; pulling a feed on demand ("Sync now") is for the owner too.
 */
class IcalFeedPolicy extends SystemSettingPolicy
{
    public function sync(User $user, IcalFeed $feed): bool
    {
        return $user->isOwner();
    }
}
