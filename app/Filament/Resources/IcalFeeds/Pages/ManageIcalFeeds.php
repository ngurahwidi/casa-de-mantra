<?php

namespace App\Filament\Resources\IcalFeeds\Pages;

use App\Filament\Resources\IcalFeeds\IcalFeedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageIcalFeeds extends ManageRecords
{
    protected static string $resource = IcalFeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
