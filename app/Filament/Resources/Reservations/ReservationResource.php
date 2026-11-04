<?php

namespace App\Filament\Resources\Reservations;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Reservations\Schemas\ReservationInfolist;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;
use App\Models\Reservation;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Reservations are read here and changed only through the actions of Fase 4, offered on the
 * view page according to the status. There is no create or edit form: bookings taken outside
 * the website are entered with "Create manual reservation" on the list page.
 */
class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'code';

    public static function infolist(Schema $schema): Schema
    {
        return ReservationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
            'view' => ViewReservation::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Reservation::query()->where('status', ReservationStatus::Pending)->count();

        return $pending === 0 ? null : (string) $pending;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pending requests';
    }

    /**
     * The same colours as the availability calendar: awaiting payment in amber, confirmed in green.
     */
    public static function statusColor(ReservationStatus $status): string
    {
        return match ($status) {
            ReservationStatus::Pending => 'info',
            ReservationStatus::AwaitingPayment => 'warning',
            ReservationStatus::Confirmed => 'success',
            ReservationStatus::Rejected, ReservationStatus::Cancelled => 'danger',
            ReservationStatus::Completed, ReservationStatus::Expired => 'gray',
        };
    }

    public static function paymentStatusColor(ReservationPaymentStatus $status): string
    {
        return match ($status) {
            ReservationPaymentStatus::Unpaid => 'gray',
            ReservationPaymentStatus::Deposit => 'warning',
            ReservationPaymentStatus::Paid => 'success',
            ReservationPaymentStatus::Refunded => 'info',
        };
    }

    /**
     * The stay as "24 Dec 2026 – 27 Dec 2026 (3 nights)", from check-in to check-out day.
     */
    public static function stay(Reservation $reservation): string
    {
        $checkIn = CarbonImmutable::parse($reservation->check_in->toDateString());
        $checkOut = CarbonImmutable::parse($reservation->check_out->toDateString());

        return $checkIn->format('j M Y').' – '.$checkOut->format('j M Y')
            .' ('.trans_choice(':count night|:count nights', (int) $checkIn->diffInDays($checkOut)).')';
    }
}
