<?php

namespace App\Filament\Resources\ReservationSources;

use App\Enums\ReservationSourceCategory;
use App\Filament\Resources\ReservationSources\Pages\ManageReservationSources;
use App\Models\ReservationSource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Where reservations are booked. Admins add the OTAs and direct channels they take bookings
 * from and pick one when entering a manual reservation. A source with reservations cannot be
 * deleted, only deactivated; the built-in website source cannot be changed (ReservationSourcePolicy).
 */
class ReservationSourceResource extends Resource
{
    protected static ?string $model = ReservationSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->helperText('As it is known to admins, e.g. Agoda or WhatsApp.')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                Radio::make('category')
                    ->options(self::categoryOptions(fn (ReservationSourceCategory $category): string => $category->label()))
                    ->descriptions(self::categoryOptions(fn (ReservationSourceCategory $category): string => $category->description()))
                    ->default(ReservationSourceCategory::Ota->value)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Only active sources can be chosen for a new manual reservation. Reservations that have a source keep it.'),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (ReservationSource $record): ?string => $record->isWebsite() ? 'Built in' : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (ReservationSourceCategory $state): string => $state->label())
                    ->color(fn (ReservationSourceCategory $state): string => match ($state) {
                        ReservationSourceCategory::Website => 'info',
                        ReservationSourceCategory::Ota => 'warning',
                        ReservationSourceCategory::Direct => 'success',
                    })
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('reservations_count')
                    ->label('Reservations')
                    ->counts('reservations')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(collect(ReservationSourceCategory::cases())
                        ->mapWithKeys(fn (ReservationSourceCategory $category): array => [$category->value => $category->label()])
                        ->all()),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('deactivate')
                    ->icon(Heroicon::OutlinedPause)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('It can no longer be chosen for a new manual reservation. Reservations that have it keep it.')
                    ->visible(fn (ReservationSource $record): bool => $record->is_active)
                    ->authorize('update')
                    ->action(fn (ReservationSource $record) => $record->update(['is_active' => false]))
                    ->successNotificationTitle('Source deactivated'),
                Action::make('activate')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('gray')
                    ->visible(fn (ReservationSource $record): bool => ! $record->is_active)
                    ->authorize('update')
                    ->action(fn (ReservationSource $record) => $record->update(['is_active' => true]))
                    ->successNotificationTitle('Source activated'),
                // A source with reservations is deactivated instead.
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReservationSources::route('/'),
        ];
    }

    /**
     * @param  callable(ReservationSourceCategory): string  $text
     * @return array<string, string>
     */
    private static function categoryOptions(callable $text): array
    {
        return collect(ReservationSourceCategory::selectable())
            ->mapWithKeys(fn (ReservationSourceCategory $category): array => [$category->value => $text($category)])
            ->all();
    }
}
