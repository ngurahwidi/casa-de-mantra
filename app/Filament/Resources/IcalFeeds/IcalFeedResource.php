<?php

namespace App\Filament\Resources\IcalFeeds;

use App\Actions\Ical\SyncIcalFeed;
use App\Enums\IcalChannel;
use App\Enums\IcalDirection;
use App\Exceptions\Ical\IcalSyncException;
use App\Filament\Resources\IcalFeeds\Pages\ManageIcalFeeds;
use App\Models\IcalFeed;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The OTA calendars of each room type. Import feeds are pulled into availability blocks
 * (SyncIcalFeed); export feeds give an OTA a link to this website's bookings (T8.2).
 *
 * A feed keeps its room type and direction once created: its blocks belong to that room
 * type's units, and the database requires a URL for imports and a token for exports.
 */
class IcalFeedResource extends Resource
{
    protected static ?string $model = IcalFeed::class;

    protected static ?string $modelLabel = 'iCal feed';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('room_type_id')
                    ->label('Room type')
                    ->relationship('roomType', 'name')
                    ->required()
                    ->disabledOn('edit'),
                Select::make('channel')
                    ->options(IcalChannel::class)
                    ->required(),
                Select::make('direction')
                    ->options(IcalDirection::class)
                    ->default(IcalDirection::Import->value)
                    ->helperText('Import blocks the dates booked on the OTA. Export gives the OTA a link to the dates booked here.')
                    ->required()
                    ->live()
                    ->disabledOn('edit'),
                TextInput::make('url')
                    ->label('Calendar link')
                    ->helperText('The iCal (.ics) export link from the OTA extranet.')
                    ->url()
                    ->rule('url:http,https')
                    ->maxLength(2048)
                    ->required()
                    // A saved feed keeps its direction, whatever the disabled field holds.
                    ->visible(fn (Get $get, ?IcalFeed $record): bool => self::isImport($record->direction ?? $get('direction')))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('roomType.name')
                    ->label('Room type')
                    ->sortable(),
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn (IcalChannel $state): string => str($state->value)->headline()),
                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (IcalDirection $state): string => $state->name)
                    ->color(fn (IcalDirection $state): string => $state === IcalDirection::Import ? 'info' : 'gray'),
                TextColumn::make('url')
                    ->label('Calendar link')
                    ->limit(50)
                    ->tooltip(fn (IcalFeed $record): ?string => $record->url)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('last_synced_at')
                    ->label('Last sync')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(fn (IcalFeed $record): string => $record->direction === IcalDirection::Import ? 'Never' : '—'),
                TextColumn::make('last_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === IcalFeed::STATUS_OK ? 'OK' : $state)
                    ->color(fn (string $state): string => $state === IcalFeed::STATUS_OK ? 'success' : 'danger')
                    ->wrap()
                    ->placeholder(fn (IcalFeed $record): string => $record->direction === IcalDirection::Import ? 'Not synced yet' : '—'),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->options(IcalDirection::class),
                SelectFilter::make('channel')
                    ->options(IcalChannel::class),
                Filter::make('failing')
                    ->label('Failing only')
                    ->query(fn (Builder $query): Builder => $query->scopes('failing')),
            ])
            ->recordActions([
                Action::make('sync')
                    ->label('Sync now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize('sync')
                    ->visible(fn (IcalFeed $record): bool => $record->direction === IcalDirection::Import)
                    ->action(function (IcalFeed $record, SyncIcalFeed $syncFeed): void {
                        try {
                            $syncFeed->handle($record);
                        } catch (IcalSyncException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Sync failed')
                                ->body($exception->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Calendar synced')
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (IcalFeed $record): string => $record->direction === IcalDirection::Import
                        ? 'The dates this calendar has blocked are opened again.'
                        : 'The OTA can no longer read the dates booked here from this link.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIcalFeeds::route('/'),
        ];
    }

    private static function isImport(IcalDirection|string|null $direction): bool
    {
        return ($direction instanceof IcalDirection ? $direction : IcalDirection::tryFrom((string) $direction)) === IcalDirection::Import;
    }
}
