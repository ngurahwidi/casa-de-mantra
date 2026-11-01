<?php

namespace App\Filament\Resources\Amenities;

use App\Filament\Resources\Amenities\Pages\ManageAmenities;
use App\Models\Amenity;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class AmenityResource extends Resource
{
    protected static ?string $model = Amenity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Property';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true),
                Select::make('icon')
                    ->options(fn (): array => static::iconOptions())
                    ->searchable(),
                TextInput::make('category')
                    ->helperText('Groups amenities on the website, e.g. outdoor, kitchen.')
                    ->maxLength(50)
                    ->datalist(fn (): array => self::categories()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                IconColumn::make('icon')
                    ->icon(fn (?string $state): ?string => $state),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('room_types_count')
                    ->label('Room types')
                    ->counts('roomTypes'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn (): array => array_combine(self::categories(), self::categories())),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('The amenity is also removed from every room type that has it.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAmenities::route('/'),
        ];
    }

    /**
     * Outline heroicons keyed by their Blade name, as stored in amenities.icon.
     *
     * @return array<string, string>
     */
    public static function iconOptions(): array
    {
        return collect(Heroicon::cases())
            ->filter(fn (Heroicon $icon): bool => str_starts_with($icon->value, 'o-'))
            ->mapWithKeys(fn (Heroicon $icon): array => [
                "heroicon-{$icon->value}" => Str::headline(substr($icon->value, 2)),
            ])
            ->sort()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function categories(): array
    {
        return Amenity::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
    }
}
