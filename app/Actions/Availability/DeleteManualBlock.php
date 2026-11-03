<?php

namespace App\Actions\Availability;

use App\Enums\AvailabilityBlockSource;
use App\Exceptions\Availability\BlockNotAllowedException;
use App\Models\AvailabilityBlock;

/**
 * An admin opens nights again by removing a block entered by hand (manual or OTA).
 *
 * Blocks imported from an iCal feed belong to the feed: the next sync would bring them back,
 * so they are only removed when the OTA calendar frees the dates (SyncIcalFeed).
 */
class DeleteManualBlock
{
    /**
     * @throws BlockNotAllowedException when the block comes from an iCal feed
     */
    public function handle(AvailabilityBlock $block): void
    {
        if ($block->source === AvailabilityBlockSource::Ical) {
            throw BlockNotAllowedException::managedByFeed();
        }

        $block->delete();
    }
}
