<?php

namespace App\Models;

use App\Enums\TaxFeeAppliesTo;
use App\Enums\TaxFeeType;
use Database\Factories\TaxFeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property TaxFeeType $type
 * @property string $value
 * @property TaxFeeAppliesTo $applies_to
 */
#[Fillable([
    'name',
    'type',
    'value',
    'is_included_in_price',
    'applies_to',
    'is_active',
])]
class TaxFee extends Model
{
    /** @use HasFactory<TaxFeeFactory> */
    use HasFactory, HasUuids;

    /**
     * @param  Builder<TaxFee>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaxFeeType::class,
            'value' => 'decimal:2',
            'is_included_in_price' => 'boolean',
            'applies_to' => TaxFeeAppliesTo::class,
            'is_active' => 'boolean',
        ];
    }
}
