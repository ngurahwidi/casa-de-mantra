<?php

namespace App\Actions\Availability;

use App\Models\AvailabilityBlock;

/**
 * An admin opens nights again by removing a block entered by hand (manual or OTA).
 */
class DeleteManualBlock
{
    public function handle(AvailabilityBlock $block): void
    {
        $block->delete();
    }
}
