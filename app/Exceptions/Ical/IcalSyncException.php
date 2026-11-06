<?php

namespace App\Exceptions\Ical;

use DomainException;

/**
 * An iCal feed could not be synced. Messages are written for admins.
 */
class IcalSyncException extends DomainException
{
    public static function notAnImportFeed(): self
    {
        return new self('Only import feeds are synced. An export feed is read by the OTA from its link.');
    }

    public static function notSetUp(): self
    {
        return new self('Syncing OTA calendars is not set up yet.');
    }
}
