<?php

namespace App\Filament\Resources\NotificationLogs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationLogStatus;
use App\Filament\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\NotificationLog;
use App\Models\PropertyProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every message the system queued, sent or failed to send. Written by the notification
 * listeners (T7.1) only, so it is read-only for every admin.
 */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 4;

    protected static bool $isGloballySearchable = false;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('reservation'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('j M Y H:i')
                    ->timezone(fn (): string => PropertyProfile::current()->timezone)
                    ->sortable(),
                TextColumn::make('reservation.code')
                    ->label('Reservation')
                    ->url(fn (NotificationLog $record): string => ReservationResource::getUrl('view', ['record' => $record->reservation_id]))
                    ->searchable(),
                TextColumn::make('template')
                    ->label('Message')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()),
                TextColumn::make('channel')
                    ->formatStateUsing(fn (NotificationChannel $state): string => str($state->value)->headline())
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (NotificationLogStatus $state): string => str($state->value)->headline())
                    ->color(fn (NotificationLogStatus $state): string => match ($state) {
                        NotificationLogStatus::Queued => 'gray',
                        NotificationLogStatus::Sent => 'success',
                        NotificationLogStatus::Failed => 'danger',
                        NotificationLogStatus::Skipped => 'warning',
                    }),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(80)
                    ->tooltip(fn (NotificationLog $record): ?string => $record->error_message)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(NotificationLogStatus::class),
                SelectFilter::make('template')
                    ->label('Message')
                    ->options(fn (): array => NotificationLog::query()
                        ->distinct()
                        ->orderBy('template')
                        ->pluck('template')
                        ->mapWithKeys(fn (string $template): array => [$template => str($template)->headline()->toString()])
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
