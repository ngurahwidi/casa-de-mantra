<?php

namespace App\Models;

use Database\Factories\IntegrationMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'entity_type',
    'internal_id',
    'provider',
    'external_id',
    'last_synced_at',
])]
class IntegrationMapping extends Model
{
    /** @use HasFactory<IntegrationMappingFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
