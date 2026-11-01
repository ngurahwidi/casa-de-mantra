<?php

namespace App\Filament\Resources\TaxFees\Pages;

use App\Filament\Resources\TaxFees\TaxFeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTaxFees extends ManageRecords
{
    protected static string $resource = TaxFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
