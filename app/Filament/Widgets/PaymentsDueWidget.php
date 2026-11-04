<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Services\Dashboard\DashboardOverview;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Unpaid reservations whose payment window is about to end, from DashboardOverview, so the
 * admin can remind the guest before the dates are released.
 */
class PaymentsDueWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Payments due soon')
            ->description('Unpaid reservations expiring within '.DashboardOverview::PAYMENT_DUE_WITHIN_HOURS.' hours. Remind the guest before the dates are released.')
            ->query(fn (): Builder => app(DashboardOverview::class)->paymentsDue())
            ->paginated(false)
            ->emptyStateHeading('No payments due soon')
            ->emptyStateDescription(null)
            ->recordUrl(fn (Reservation $record): string => ReservationResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('guest_full_name')
                    ->label('Guest')
                    ->description(fn (Reservation $record): string => $record->guest_phone),
                TextColumn::make('stay')
                    ->state(fn (Reservation $record): string => ReservationResource::stay($record)),
                TextColumn::make('total')
                    ->money(fn (Reservation $record): string => $record->currency),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->since()
                    ->dateTimeTooltip('j M Y H:i', timezone: PropertyProfile::current()->timezone)
                    ->color(fn (Reservation $record): ?string => $record->expires_at?->isPast() ? 'danger' : null),
            ]);
    }
}
