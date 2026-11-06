<?php

namespace App\Models;

use App\Enums\UnitStatus;
use App\Policies\SystemSettingPolicy;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property UnitStatus $status
 */
#[Fillable(['room_type_id', 'code', 'status'])]
#[UsePolicy(SystemSettingPolicy::class)]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
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
     * @return HasMany<ReservationRoom, $this>
     */
    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /**
     * Reservations restrict deletion at the database level; such a unit is set inactive instead.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->reservationRooms()->exists();
    }

    /**
     * @param  Builder<Unit>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UnitStatus::Active);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UnitStatus::class,
        ];
    }
}
