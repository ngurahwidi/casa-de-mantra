<?php

namespace App\Filament\Resources\PropertyProfiles\Pages;

use App\Filament\Resources\PropertyProfiles\PropertyProfileResource;
use App\Models\PropertyProfile;
use Filament\Resources\Pages\EditRecord;

class EditPropertyProfile extends EditRecord
{
    protected static string $resource = PropertyProfileResource::class;

    /**
     * The route has no {record} segment: there is only ever the one profile row.
     */
    public function mount(int|string $record = PropertyProfile::ID): void
    {
        parent::mount(PropertyProfile::ID);
    }

    public function getTitle(): string
    {
        return 'Property Profile';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
