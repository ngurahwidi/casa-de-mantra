<?php

namespace App\Data\Readiness;

use App\Models\IcalFeed;
use Illuminate\Support\Collection;

/**
 * One snapshot of the "Ready to sell?" checks, computed once by ReadinessCheck::report() so
 * the page and the dashboard stat share the queries and the copy of every failed check.
 */
final readonly class ReadinessReport
{
    /**
     * @param  bool  $ready  the villa can take booking requests
     * @param  list<string>  $issues  one line per failed check, for the verdict banner and the dashboard stat
     * @param  list<string>  $missingProfileFields  blank profile fields, by name
     * @param  list<string>  $inactiveNotificationTemplates  required template codes that are missing or inactive
     * @param  Collection<int, IcalFeed>  $failingIcalFeeds  import feeds whose latest sync failed
     * @param  Collection<int, RoomTypeReadiness>  $roomTypes  every room type by name
     * @param  int  $incompletePublishedRoomTypes  published room types that fail a mandatory check
     */
    public function __construct(
        public bool $ready,
        public array $issues,
        public array $missingProfileFields,
        public array $inactiveNotificationTemplates,
        public Collection $failingIcalFeeds,
        public Collection $roomTypes,
        public int $incompletePublishedRoomTypes,
    ) {}
}
