<?php

namespace App\Actions\Content;

use App\Models\PropertyProfile;

/**
 * Deletes the uploaded homepage banner, file included. The hero then shows the first villa photo again.
 */
class RemoveHomepageBanner
{
    public function handle(PropertyProfile $profile): void
    {
        $profile->clearMediaCollection(PropertyProfile::HERO);
    }
}
