<?php

namespace App\Filament\Widgets;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Services\Dashboard\DashboardOverview;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Today's check-outs and check-ins, from DashboardOverview.
 */
class TodaysStaysWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading("Today's check-ins and check-outs")
            ->query(fn (): Builder => app(DashboardOverview::class)->todaysStays()->with('reservationRooms.unit'))
            ->paginated(false)
            ->emptyStateHeading('No check-ins or check-outs today')
            ->emptyStateDescription(null)
            ->recordUrl(fn (Reservation $record): string => ReservationResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('movement')
                    ->label('Today')
                    ->badge()
                    ->state(fn (Reservation $record): string => self::isCheckIn($record) ? 'Check-in' : 'Check-out')
                    ->color(fn (Reservation $record): string => self::isCheckIn($record) ? 'success' : 'gray'),
                TextColumn::make('code'),
                TextColumn::make('guest_full_name')
                    ->label('Guest')
                    ->description(fn (Reservation $record): string => $record->guest_phone),
                TextColumn::make('reservationRooms.unit.code')
                    ->label('Unit')
                    ->placeholder('Not assigned'),
                TextColumn::make('stay')
                    ->state(fn (Reservation $record): string => ReservationResource::stay($record)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ReservationStatus $state): string => $state->label())
                    ->color(fn (ReservationStatus $state): string => ReservationResource::statusColor($state)),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (ReservationPaymentStatus $state): string => $state->label())
                    ->color(fn (ReservationPaymentStatus $state): string => ReservationResource::paymentStatusColor($state)),
            ]);
    }

    private static function isCheckIn(Reservation $reservation): bool
    {
        return $reservation->check_in->toDateString() === now(PropertyProfile::current()->timezone)->toDateString();
    }
}
