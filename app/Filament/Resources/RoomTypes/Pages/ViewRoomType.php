<?php

namespace App\Filament\Resources\RoomTypes\Pages;

use App\Filament\Resources\RoomTypes\RoomTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The room type read-only, for staff: only the owner changes room types, units, amenities and
 * photos (SystemSettingPolicy). Relation managers are read-only on this page.
 */
class ViewRoomType extends ViewRecord
{
    protected static string $resource = RoomTypeResource::class;

    protected static ?string $navigationLabel = 'Details';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
