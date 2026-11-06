<?php

namespace App\Actions\Ical;

use App\Enums\IcalDirection;
use App\Exceptions\Ical\IcalSyncException;
use App\Models\IcalFeed;

/**
 * Pulls the OTA calendar of an import feed into availability blocks (T8.1).
 *
 * Only the entry point exists so far, for the admin's "Sync now" button: until the import is
 * built, every sync fails without touching the feed or its blocks.
 */
class SyncIcalFeed
{
    /**
     * @throws IcalSyncException
     */
    public function handle(IcalFeed $feed): void
    {
        if ($feed->direction !== IcalDirection::Import) {
            throw IcalSyncException::notAnImportFeed();
        }

        throw IcalSyncException::notSetUp();
    }
}
