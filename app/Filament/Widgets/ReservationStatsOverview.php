<?php

namespace App\Filament\Widgets;

use App\Enums\ReservationStatus;
use App\Filament\Pages\ReadyToSell;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\IcalFeed;
use App\Models\PropertyProfile;
use App\Services\Dashboard\DashboardOverview;
use App\Services\Readiness\ReadinessCheck;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The dashboard's numbers, from DashboardOverview. Stats with a matching reservation list link to it.
 */
class ReservationStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected int|array|null $columns = 4;

    protected function getStats(): array
    {
        $overview = app(DashboardOverview::class);

        return [
            $this->pendingRequests($overview),
            $this->paymentsDue($overview),
            Stat::make('Check-ins today', $overview->checkInsToday()->count())
                ->icon(Heroicon::OutlinedArrowRightEndOnRectangle),
            Stat::make('Check-outs today', $overview->checkOutsToday()->count())
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle),
            $this->notBlockedOnOta($overview),
            $this->icalSync($overview),
            $this->occupancy($overview),
            $this->readiness(),
        ];
    }

    private function pendingRequests(DashboardOverview $overview): Stat
    {
        $count = $overview->pendingRequests()->count();
        $oldest = $overview->oldestPendingRequestedAt();
        $isOverdue = $oldest !== null && $oldest->lt(now()->subHours(PropertyProfile::current()->pending_reminder_hours));

        return Stat::make('Pending requests', $count)
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->description($oldest === null ? 'Nothing waiting' : 'Oldest requested '.$oldest->diffForHumans())
            ->color(match (true) {
                $isOverdue => 'danger',
                $count > 0 => 'warning',
                default => 'gray',
            })
            ->url(ReservationResource::getUrl('index', [
                'filters' => ['status' => ['values' => [ReservationStatus::Pending->value]]],
            ]));
    }

    private function paymentsDue(DashboardOverview $overview): Stat
    {
        $count = $overview->paymentsDue()->count();

        return Stat::make('Payments due soon', $count)
            ->icon(Heroicon::OutlinedClock)
            ->description('Unpaid, expiring within '.DashboardOverview::PAYMENT_DUE_WITHIN_HOURS.' hours')
            ->color($count > 0 ? 'danger' : 'gray');
    }

    private function notBlockedOnOta(DashboardOverview $overview): Stat
    {
        $count = $overview->notBlockedOnOta()->count();

        return Stat::make('Not blocked on OTA', $count)
            ->icon(Heroicon::OutlinedNoSymbol)
            ->description('Confirmed stays still open on the OTA calendars')
            ->color($count > 0 ? 'warning' : 'gray')
            ->url(ReservationResource::getUrl('index', [
                'filters' => [
                    'status' => ['values' => [ReservationStatus::Confirmed->value]],
                    'ota_blocked_at' => ['value' => '0'],
                    'stay' => ['from' => now(PropertyProfile::current()->timezone)->toDateString()],
                ],
            ]));
    }

    private function icalSync(DashboardOverview $overview): Stat
    {
        $failing = $overview->failingIcalFeeds()->with('roomType')->get();

        return Stat::make('iCal sync', $failing->isEmpty() ? 'OK' : trans_choice(':count feed failing|:count feeds failing', $failing->count()))
            ->icon(Heroicon::OutlinedArrowPath)
            ->description($failing->isEmpty()
                ? 'No failed imports'
                : $failing->map(fn (IcalFeed $feed): string => $feed->roomType->name.' ('.str($feed->channel->value)->headline().')')->implode(', '))
            ->color($failing->isEmpty() ? 'success' : 'danger');
    }

    private function occupancy(DashboardOverview $overview): Stat
    {
        $occupancy = $overview->occupancyThisMonth();
        $percentage = $occupancy->percentage();

        return Stat::make('Occupancy this month', $percentage === null ? '–' : $percentage.'%')
            ->icon(Heroicon::OutlinedChartPie)
            ->description($occupancy->soldNights.' of '.$occupancy->availableNights.' nights sold in '.CarbonImmutable::parse($occupancy->month)->format('F'));
    }

    private function readiness(): Stat
    {
        // One report for every readiness question, so the checks run once per dashboard render.
        $report = app(ReadinessCheck::class)->report();

        return Stat::make('Ready to sell?', $report->ready ? 'Yes' : 'No')
            ->icon($report->ready ? Heroicon::OutlinedShieldCheck : Heroicon::OutlinedShieldExclamation)
            ->description(match (true) {
                $report->issues === [] => 'Every check passed',
                default => trans_choice('{1} One issue to fix|{2,*} :count issues to fix', count($report->issues)),
            })
            ->color(match (true) {
                $report->issues === [] => 'success',
                // A published room type that cannot be booked is the worst case: the site offers it.
                $report->incompletePublishedRoomTypes > 0 => 'danger',
                default => 'warning',
            })
            ->url(ReadyToSell::getUrl());
    }
}
