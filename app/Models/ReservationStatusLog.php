<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Database\Factories\ReservationStatusLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ReservationStatus|null $from_status
 * @property ReservationStatus $to_status
 */
#[Fillable(['reservation_id', 'admin_user_id', 'from_status', 'to_status', 'note'])]
class ReservationStatusLog extends Model
{
    /** @use HasFactory<ReservationStatusLogFactory> */
    use HasFactory, HasUuids;

    /** The log is append-only; the table has no updated_at. */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ReservationStatus::class,
            'to_status' => ReservationStatus::class,
        ];
    }
}
