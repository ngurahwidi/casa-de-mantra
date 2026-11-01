<?php

namespace App\Filament\Resources\Reviews;

use App\Filament\Resources\Reviews\Pages\ManageReviews;
use App\Models\Review;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'guest_name';

    /**
     * Suggestions only: reviews.source is free text.
     */
    private const SOURCES = ['Google', 'Booking.com', 'Airbnb', 'Expedia', 'TripAdvisor', 'Direct'];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('guest_name')
                    ->required()
                    ->maxLength(255),
                Select::make('rating')
                    ->options(self::ratingOptions())
                    ->required(),
                TextInput::make('source')
                    ->helperText('Where the review was left.')
                    ->maxLength(50)
                    ->datalist(self::SOURCES),
                Toggle::make('is_published')
                    ->label('Published')
                    ->inline(false),
                Textarea::make('comment')
                    ->rows(5)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('guest_name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('guest_name')
                    ->searchable(),
                TextColumn::make('rating')
                    ->formatStateUsing(fn (int $state): string => self::ratingOptions()[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('comment')
                    ->limit(80)
                    ->toggleable(),
                TextColumn::make('source')
                    ->searchable(),
                ToggleColumn::make('is_published')
                    ->label('Published'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Published'),
                SelectFilter::make('rating')
                    ->options(self::ratingOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReviews::route('/'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function ratingOptions(): array
    {
        return collect(range(5, 1))
            ->mapWithKeys(fn (int $stars): array => [$stars => str_repeat('★', $stars).str_repeat('☆', 5 - $stars)])
            ->all();
    }
}
