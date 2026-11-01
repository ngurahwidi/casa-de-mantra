<?php

namespace App\Filament\Resources\TaxFees;

use App\Enums\TaxFeeAppliesTo;
use App\Enums\TaxFeeType;
use App\Filament\Resources\TaxFees\Pages\ManageTaxFees;
use App\Models\TaxFee;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Taxes are not used in phase 1, so this resource is kept out of the navigation.
 * It stays reachable by URL (/admin/tax-fees) for when taxes are switched on.
 */
class TaxFeeResource extends Resource
{
    protected static ?string $model = TaxFee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static bool $shouldRegisterNavigation = false;

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(TaxFeeType::class)
                    ->required()
                    ->live(),
                TextInput::make('value')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Get $get): ?int => self::isPercent($get('type')) ? 100 : null)
                    ->suffix(fn (Get $get): ?string => self::isPercent($get('type')) ? '%' : null),
                Select::make('applies_to')
                    ->options(self::appliesToOptions())
                    ->default(TaxFeeAppliesTo::PerNight->value)
                    ->required(),
                Toggle::make('is_included_in_price')
                    ->label('Included in price'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->helperText('Only active rows are added to guest prices.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (TaxFeeType $state): string => $state->name),
                TextColumn::make('value')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('applies_to')
                    ->formatStateUsing(fn (TaxFeeAppliesTo $state): string => self::appliesToOptions()[$state->value]),
                IconColumn::make('is_included_in_price')
                    ->label('Included')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTaxFees::route('/'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function appliesToOptions(): array
    {
        return collect(TaxFeeAppliesTo::cases())
            ->mapWithKeys(fn (TaxFeeAppliesTo $case): array => [$case->value => Str::headline($case->name)])
            ->all();
    }

    private static function isPercent(TaxFeeType|string|null $type): bool
    {
        return ($type instanceof TaxFeeType ? $type : TaxFeeType::tryFrom((string) $type)) === TaxFeeType::Percent;
    }
}
