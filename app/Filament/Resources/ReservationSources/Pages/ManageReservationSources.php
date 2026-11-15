<?php

namespace App\Filament\Resources\ReservationSources\Pages;

use App\Filament\Resources\ReservationSources\ReservationSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReservationSources extends ManageRecords
{
    protected static string $resource = ReservationSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
