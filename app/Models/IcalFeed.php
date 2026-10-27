<?php

namespace App\Models;

use App\Enums\IcalChannel;
use App\Enums\IcalDirection;
use Database\Factories\IcalFeedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'room_type_id',
    'channel',
    'direction',
    'url',
    'export_token',
    'last_synced_at',
    'last_status',
])]
class IcalFeed extends Model
{
    /** @use HasFactory<IcalFeedFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return HasMany<AvailabilityBlock, $this>
     */
    public function availabilityBlocks(): HasMany
    {
        return $this->hasMany(AvailabilityBlock::class);
    }

    /**
     * @param  Builder<IcalFeed>  $query
     */
    public function scopeImports(Builder $query): void
    {
        $query->where('direction', IcalDirection::Import);
    }

    /**
     * @param  Builder<IcalFeed>  $query
     */
    public function scopeExports(Builder $query): void
    {
        $query->where('direction', IcalDirection::Export);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => IcalChannel::class,
            'direction' => IcalDirection::class,
            'last_synced_at' => 'datetime',
        ];
    }
}
