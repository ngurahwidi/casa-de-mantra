<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['reservationRooms.roomType', 'reservationRooms.unit']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('guest_full_name')
                    ->label('Guest')
                    ->description(fn (Reservation $record): string => $record->guest_email)
                    ->searchable(['guest_full_name', 'guest_email']),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ReservationStatus $state): string => $state->label())
                    ->color(fn (ReservationStatus $state): string => ReservationResource::statusColor($state)),
                TextColumn::make('check_in')
                    ->label('Check-in')
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('check_out')
                    ->label('Check-out')
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('reservationRooms.roomType.name')
                    ->label('Room type')
                    ->toggleable(),
                TextColumn::make('reservationRooms.unit.code')
                    ->label('Unit')
                    ->placeholder('Not assigned')
                    ->toggleable(),
                TextColumn::make('total')
                    ->money(fn (Reservation $record): string => $record->currency)
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (ReservationPaymentStatus $state): string => $state->label())
                    ->color(fn (ReservationPaymentStatus $state): string => ReservationResource::paymentStatusColor($state)),
                TextColumn::make('source')
                    ->formatStateUsing(fn (ReservationSource $state): string => $state->label())
                    ->toggleable(),
                IconColumn::make('ota_blocked_at')
                    ->label('Blocked on OTA')
                    ->state(fn (Reservation $record): bool => $record->ota_blocked_at !== null)
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->since()
                    ->dateTimeTooltip('j M Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(self::labels(ReservationStatus::cases())),
                SelectFilter::make('source')
                    ->multiple()
                    ->options(self::labels(ReservationSource::cases())),
                Filter::make('stay')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Staying from')
                            ->native(false)
                            ->displayFormat('j M Y'),
                        DatePicker::make('until')
                            ->label('Staying until')
                            ->native(false)
                            ->displayFormat('j M Y')
                            ->afterOrEqual('from'),
                    ])
                    // Stays with at least one night in [from, until]; the check-out day is not a night.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('check_out', '>', $from))
                        ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('check_in', '<=', $until)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['from'] ?? null)) {
                            $indicators[] = 'Staying from '.CarbonImmutable::parse($data['from'])->format('j M Y');
                        }

                        if (filled($data['until'] ?? null)) {
                            $indicators[] = 'Staying until '.CarbonImmutable::parse($data['until'])->format('j M Y');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * @param  list<ReservationStatus|ReservationSource>  $cases
     * @return array<string, string>
     */
    private static function labels(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn (ReservationStatus|ReservationSource $case): array => [$case->value => $case->label()])
            ->all();
    }
}
