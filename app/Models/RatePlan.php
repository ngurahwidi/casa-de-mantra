<?php

namespace App\Models;

use App\Enums\MealPlan;
use Database\Factories\RatePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MealPlan $meal_plan
 */
#[Fillable([
    'room_type_id',
    'cancellation_policy_id',
    'name',
    'meal_plan',
    'min_stay',
    'max_stay',
    'is_active',
])]
class RatePlan extends Model
{
    /** @use HasFactory<RatePlanFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return BelongsTo<CancellationPolicy, $this>
     */
    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    /**
     * @return HasMany<RateRule, $this>
     */
    public function rateRules(): HasMany
    {
        return $this->hasMany(RateRule::class);
    }

    /**
     * @return HasMany<ReservationRoom, $this>
     */
    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /**
     * @param  Builder<RatePlan>  $query
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
            'meal_plan' => MealPlan::class,
            'min_stay' => 'integer',
            'max_stay' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
