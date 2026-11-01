<?php

namespace App\Filament\Resources\RoomTypes\Pages;

use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Models\RoomType;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRoomType extends EditRecord
{
    protected static string $resource = RoomTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A room type with units, prices or reservations is unpublished instead.
            DeleteAction::make()
                ->visible(fn (RoomType $record): bool => $record->canBeDeleted()),
        ];
    }
}
