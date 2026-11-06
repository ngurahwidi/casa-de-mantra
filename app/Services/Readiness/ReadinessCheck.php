<?php

namespace App\Services\Readiness;

use App\Data\Readiness\ReadinessReport;
use App\Data\Readiness\RoomTypeReadiness;
use App\Data\Readiness\UnpricedRange;
use App\Enums\NotificationChannel;
use App\Models\IcalFeed;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Pricing\NightlyRates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The "Ready to sell?" check: what the booking flow and the public site need before the villa
 * can take requests, and what a room type needs before it may be published.
 *
 * Pricing follows the booking flow itself: every night of the next PRICED_NIGHTS_AHEAD days is
 * resolved with NightlyRates, the same resolution CalculatePrice uses, and a night that no rule
 * covers cannot be sold.
 */
class ReadinessCheck
{
    /** Nights that must have a price, counted from today in the property timezone. */
    public const PRICED_NIGHTS_AHEAD = 365;

    /**
     * Profile fields the public site and the booking flow need. Blank ones are reported by name.
     * seo_settings and theme fall back to their defaults and are not checked.
     */
    public const PROFILE_FIELDS = [
        'type', 'name', 'timezone', 'currency', 'checkin_time', 'checkout_time', 'whatsapp',
        'email', 'address', 'lat', 'lng', 'bank_account_info',
    ];

    /**
     * Email templates the reservation events send; each must exist and be active. The
     * pre-arrival reminder is a scheduled courtesy (T7.2) and is not needed to sell.
     */
    public const NOTIFICATION_CODES = [
        'reservation_received', 'reservation_awaiting_payment', 'reservation_confirmed',
        'reservation_rejected', 'reservation_expired', 'reservation_cancelled', 'admin_new_request',
    ];

    public function __construct(private readonly NightlyRates $nightlyRates) {}

    /**
     * @return list<string> the blank profile fields, in PROFILE_FIELDS order
     */
    public function missingProfileFields(): array
    {
        $profile = PropertyProfile::current();

        $missing = [];

        foreach (self::PROFILE_FIELDS as $field) {
            if (blank($profile->getAttribute($field))) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @return list<string> required templates that are missing or inactive, in NOTIFICATION_CODES order
     */
    public function inactiveNotificationTemplates(): array
    {
        $active = NotificationTemplate::query()
            ->where('channel', NotificationChannel::Email)
            ->whereIn('code', self::NOTIFICATION_CODES)
            ->active()
            ->pluck('code')
            ->all();

        return array_values(array_diff(self::NOTIFICATION_CODES, $active));
    }

    /**
     * Import feeds whose latest sync failed. Feeds that have never synced are not failing yet.
     *
     * @return Collection<int, IcalFeed>
     */
    public function failingIcalFeeds(): Collection
    {
        return IcalFeed::query()
            ->failing()
            ->with('roomType')
            ->get()
            ->sortBy(fn (IcalFeed $feed): array => [$feed->roomType->name, $feed->channel->value])
            ->values();
    }

    /**
     * @return Collection<int, RoomTypeReadiness> every room type by name
     */
    private function roomTypes(): Collection
    {
        return RoomType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (RoomType $roomType): RoomTypeReadiness => $this->roomType($roomType));
    }

    public function roomType(RoomType $roomType): RoomTypeReadiness
    {
        $ratePlan = $roomType->nonRefundableRatePlan()->first();

        return new RoomTypeReadiness(
            $roomType,
            $roomType->max_adults >= 1,
            $roomType->media()->where('collection_name', RoomType::GALLERY)->exists(),
            $roomType->units()->active()->exists(),
            // A rate plan always has a cancellation policy (the column is required), so an
            // existing active Non-Refundable plan is all the terms the booking needs.
            $ratePlan !== null && $ratePlan->is_active,
            $this->unpricedRanges($ratePlan),
            self::PRICED_NIGHTS_AHEAD,
        );
    }

    /**
     * One snapshot of every check, computed once: the page, the verdict banner and the
     * dashboard stat all read it, so each query runs once per render. A published room type
     * with failed checks is reported even when the villa is otherwise ready to sell.
     */
    public function report(): ReadinessReport
    {
        $missingProfileFields = $this->missingProfileFields();
        $inactiveNotificationTemplates = $this->inactiveNotificationTemplates();
        $failingIcalFeeds = $this->failingIcalFeeds();
        $roomTypes = $this->roomTypes();

        $hasCompletePublishedRoomType = $roomTypes->contains(
            fn (RoomTypeReadiness $readiness): bool => $readiness->isComplete() && $readiness->roomType->is_published,
        );
        // Publishing is guarded against this, so it happens when a published room type loses
        // something later, e.g. its last photo.
        $incompletePublished = $roomTypes
            ->filter(fn (RoomTypeReadiness $readiness): bool => $readiness->roomType->is_published && ! $readiness->isComplete())
            ->values();

        $issues = [];

        if ($missingProfileFields !== []) {
            $issues[] = 'the property profile is incomplete';
        }

        if ($inactiveNotificationTemplates !== []) {
            $issues[] = 'notification templates are missing or inactive';
        }

        if ($failingIcalFeeds->isNotEmpty()) {
            $issues[] = trans_choice(
                '{1} One iCal feed is failing to import|{2,*} :count iCal feeds are failing to import',
                $failingIcalFeeds->count(),
            );
        }

        if ($incompletePublished->isNotEmpty()) {
            $issues[] = trans_choice(
                '{1} One published room type is incomplete|{2,*} :count published room types are incomplete',
                $incompletePublished->count(),
            );
        }

        if (! $hasCompletePublishedRoomType) {
            $issues[] = 'no complete room type is published';
        }

        return new ReadinessReport(
            ready: $missingProfileFields === []
                && $inactiveNotificationTemplates === []
                && $failingIcalFeeds->isEmpty()
                && $hasCompletePublishedRoomType,
            issues: $issues,
            missingProfileFields: $missingProfileFields,
            inactiveNotificationTemplates: $inactiveNotificationTemplates,
            failingIcalFeeds: $failingIcalFeeds,
            roomTypes: $roomTypes,
            incompletePublishedRoomTypes: $incompletePublished->count(),
        );
    }

    /**
     * Runs of nights without a price within [today, today + PRICED_NIGHTS_AHEAD). Without an
     * active rate plan every night of the window is missing, as one run.
     *
     * @return list<UnpricedRange>
     */
    private function unpricedRanges(?RatePlan $ratePlan): array
    {
        $today = CarbonImmutable::parse(now(PropertyProfile::current()->timezone)->toDateString());
        $end = $today->addDays(self::PRICED_NIGHTS_AHEAD);

        if ($ratePlan === null || ! $ratePlan->is_active) {
            return [new UnpricedRange($today->toDateString(), $end->subDay()->toDateString())];
        }

        $nights = $this->nightlyRates->forPeriod($ratePlan, $today->toDateString(), $end->toDateString());

        return self::runs(array_keys(array_filter($nights, fn (?object $rule): bool => $rule === null)));
    }

    /**
     * @param  list<string>  $dates  nights as Y-m-d, in window order
     * @return list<UnpricedRange> runs of consecutive nights
     */
    private static function runs(array $dates): array
    {
        $runs = [];
        $from = $to = null;

        foreach ($dates as $date) {
            if ($from !== null && CarbonImmutable::parse($date)->subDay()->toDateString() === $to) {
                $to = $date;
            } else {
                if ($from !== null) {
                    $runs[] = new UnpricedRange($from, $to);
                }

                $from = $to = $date;
            }
        }

        if ($from !== null) {
            $runs[] = new UnpricedRange($from, $to);
        }

        return $runs;
    }
}
