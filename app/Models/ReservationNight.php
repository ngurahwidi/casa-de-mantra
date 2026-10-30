<?php

namespace App\Models;

use Database\Factories\ReservationNightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $night_date
 * @property string $price decimal with two places
 */
#[Fillable(['reservation_room_id', 'night_date', 'price'])]
class ReservationNight extends Model
{
    /** @use HasFactory<ReservationNightFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<ReservationRoom, $this>
     */
    public function reservationRoom(): BelongsTo
    {
        return $this->belongsTo(ReservationRoom::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'night_date' => 'date',
            'price' => 'decimal:2',
        ];
    }
}
