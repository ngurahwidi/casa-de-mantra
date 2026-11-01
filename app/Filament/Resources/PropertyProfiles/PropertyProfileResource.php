<?php

namespace App\Filament\Resources\PropertyProfiles;

use App\Filament\Resources\PropertyProfiles\Pages\EditPropertyProfile;
use App\Filament\Resources\PropertyProfiles\Schemas\PropertyProfileForm;
use App\Models\PropertyProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The single property profile row: edited in place, never created or deleted from the panel.
 */
class PropertyProfileResource extends Resource
{
    protected static ?string $model = PropertyProfile::class;

    protected static ?string $slug = 'property-profile';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'Property';

    protected static ?string $navigationLabel = 'Property Profile';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return PropertyProfileForm::configure($schema);
    }

    public static function canCreate(): bool
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

    public static function getPages(): array
    {
        return [
            'index' => EditPropertyProfile::route('/'),
        ];
    }
}
