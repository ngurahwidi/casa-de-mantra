<?php

namespace App\Models;

use App\Enums\AvailabilityBlockSource;
use Database\Factories\AvailabilityBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'unit_id',
    'ical_feed_id',
    'start_date',
    'end_date',
    'source',
    'reason',
    'external_uid',
])]
class AvailabilityBlock extends Model
{
    /** @use HasFactory<AvailabilityBlockFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<IcalFeed, $this>
     */
    public function icalFeed(): BelongsTo
    {
        return $this->belongsTo(IcalFeed::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'source' => AvailabilityBlockSource::class,
        ];
    }
}
