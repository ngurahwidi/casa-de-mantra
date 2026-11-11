<?php

namespace App\Filament\Pages;

use App\Data\Readiness\RoomTypeReadiness;
use App\Data\Readiness\UnpricedRange;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\PropertyProfiles\PropertyProfileResource;
use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Services\Readiness\ReadinessCheck;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The "Ready to sell?" page: everything that must be in place before the villa can take
 * booking requests, from ReadinessCheck. Each failed check says what is missing and links to
 * where it is fixed, and each room type lists its nights without a price.
 */
class ReadyToSell extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Ready to sell?';

    protected static ?string $navigationLabel = 'Ready to sell?';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 2;

    /** Field names from ReadinessCheck::PROFILE_FIELDS as the admin knows them. */
    private const PROFILE_LABELS = [
        'type' => 'Property type',
        'name' => 'Name',
        'timezone' => 'Time zone',
        'currency' => 'Currency',
        'checkin_time' => 'Check-in time',
        'checkout_time' => 'Check-out time',
        'whatsapp' => 'WhatsApp number',
        'email' => 'Email',
        'address' => 'Address',
        'lat' => 'Map latitude',
        'lng' => 'Map longitude',
        'bank_account_info' => 'Bank account details',
    ];

    /** Template codes from ReadinessCheck::NOTIFICATION_CODES as the admin knows them. */
    private const NOTIFICATION_LABELS = [
        'reservation_received' => 'Reservation received',
        'reservation_awaiting_payment' => 'Reservation awaiting payment',
        'reservation_confirmed' => 'Reservation confirmed',
        'reservation_rejected' => 'Reservation rejected',
        'reservation_expired' => 'Reservation expired',
        'reservation_cancelled' => 'Reservation cancelled',
        'admin_new_request' => 'New request (admin)',
    ];

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.ready-to-sell')
                ->viewData(fn (): array => $this->report()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $report = app(ReadinessCheck::class)->report();

        return [
            'ready' => $report->ready,
            'issues' => $report->issues,
            'profile' => [
                // Missing fields are reported by name, in the order the profile form shows them.
                'missing' => array_map(
                    fn (string $field): string => self::PROFILE_LABELS[$field] ?? $field,
                    $report->missingProfileFields,
                ),
                'url' => PropertyProfileResource::getUrl('index'),
            ],
            'templates' => [
                'inactive' => array_map(
                    fn (string $code): string => self::NOTIFICATION_LABELS[$code] ?? $code,
                    $report->inactiveNotificationTemplates,
                ),
                'url' => NotificationTemplateResource::getUrl('index'),
            ],
            'roomTypes' => $report->roomTypes
                ->map(fn (RoomTypeReadiness $readiness): array => [
                    'readiness' => $readiness,
                    'checks' => [
                        ['key' => 'capacity', 'label' => 'A capacity of at least one adult', 'passes' => $readiness->hasCapacity],
                        ['key' => 'photo', 'label' => 'At least one photo', 'passes' => $readiness->hasPhoto],
                        ['key' => 'unit', 'label' => 'An active unit', 'passes' => $readiness->hasActiveUnit],
                        ['key' => 'base_price', 'label' => 'A base price', 'passes' => $readiness->hasBasePrice],
                        ['key' => 'nights', 'label' => 'A price for every night of the next '.$readiness->nightsAhead.' days', 'passes' => $readiness->hasAllNightsPriced()],
                    ],
                    // A long window without prices yields a very long list; the first runs carry the message.
                    'unpriced' => array_map(
                        fn (UnpricedRange $range): string => $range->label(),
                        array_slice($readiness->unpricedRanges, 0, 8),
                    ),
                    'moreUnpriced' => max(0, count($readiness->unpricedRanges) - 8),
                    'detailsUrl' => RoomTypeResource::getUrl(
                        RoomTypeResource::canEdit($readiness->roomType) ? 'edit' : 'view',
                        ['record' => $readiness->roomType],
                    ),
                    'pricingUrl' => RoomTypeResource::getUrl('pricing', ['record' => $readiness->roomType]),
                ])
                ->all(),
        ];
    }
}
