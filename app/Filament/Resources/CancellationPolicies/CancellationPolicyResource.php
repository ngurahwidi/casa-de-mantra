<?php

namespace App\Filament\Resources\CancellationPolicies;

use App\Filament\Resources\CancellationPolicies\Pages\ManageCancellationPolicies;
use App\Models\CancellationPolicy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The cancellation policy text guests agree to. Only the Non-Refundable policy is sold in phase 1
 * and rate plans find it by name, so policies are not created, renamed or deleted here; only the
 * wording guests read is edited.
 */
class CancellationPolicyResource extends Resource
{
    protected static ?string $model = CancellationPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->disabled(),
                Textarea::make('description')
                    ->label('Text for guests')
                    ->helperText('Shown to guests before they send a request. Each reservation keeps the text it was booked with, so changes apply to new requests only.')
                    ->required()
                    ->rows(12)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('description')
                    ->label('Text for guests')
                    ->limit(100)
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // Staff may read the policy but not change it.
                ViewAction::make()
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->hidden(fn (CancellationPolicy $record): bool => static::canEdit($record)),
                EditAction::make()
                    ->modalWidth(Width::ThreeExtraLarge),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCancellationPolicies::route('/'),
        ];
    }
}
