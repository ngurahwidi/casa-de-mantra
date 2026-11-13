<?php

namespace App\Filament\Widgets;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Services\Dashboard\DashboardOverview;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Completed stays that are not paid in full, from DashboardOverview, so a balance the guest was
 * to settle at check-out is not forgotten. The payment is recorded on the reservation page.
 */
class OutstandingBalancesWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Completed, not fully paid')
            ->description('Stays that have ended with a balance still to collect. Open one to record the payment.')
            ->query(fn (): Builder => app(DashboardOverview::class)->outstandingBalances())
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No outstanding balances')
            ->emptyStateDescription(null)
            ->recordUrl(fn (Reservation $record): string => ReservationResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('guest_full_name')
                    ->label('Guest')
                    ->description(fn (Reservation $record): string => $record->guest_phone),
                TextColumn::make('source')
                    ->formatStateUsing(fn (ReservationSource $state): string => $state->label()),
                TextColumn::make('stay')
                    ->state(fn (Reservation $record): string => ReservationResource::stay($record)),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (ReservationPaymentStatus $state): string => $state->label())
                    ->color(fn (ReservationPaymentStatus $state): string => ReservationResource::paymentStatusColor($state)),
                TextColumn::make('total')
                    ->money(fn (Reservation $record): string => $record->currency),
                TextColumn::make('balance')
                    ->money(fn (Reservation $record): string => $record->currency)
                    ->color('danger'),
            ]);
    }
}
