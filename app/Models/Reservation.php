<?php

namespace App\Models;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * Status changes go through ReservationStateMachine (T4.3), never by assigning `status` directly.
 *
 * guest_full_name, guest_email, guest_phone and guest_country are the contact details given
 * with this reservation; use them to contact the guest about it, not the guest record.
 *
 * @property ReservationStatus $status
 * @property ReservationPaymentStatus $payment_status
 * @property Carbon $check_in
 * @property Carbon $check_out
 * @property string $total decimal with two places
 * @property Carbon|null $expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $ota_blocked_at
 * @property array<string, mixed>|null $policy_snapshot
 */
#[Fillable([
    'code',
    'guest_id',
    'guest_full_name',
    'guest_email',
    'guest_phone',
    'guest_country',
    'status',
    'source',
    'check_in',
    'check_out',
    'adults',
    'children',
    'special_requests',
    'currency',
    'subtotal',
    'tax_total',
    'total',
    'payment_status',
    'expires_at',
    'confirmed_at',
    'ota_blocked_at',
    'reject_reason',
    'external_source',
    'external_id',
    'policy_snapshot',
])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return HasMany<ReservationRoom, $this>
     */
    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /**
     * @return HasManyThrough<ReservationNight, ReservationRoom, $this>
     */
    public function nights(): HasManyThrough
    {
        return $this->hasManyThrough(ReservationNight::class, ReservationRoom::class);
    }

    /**
     * @return HasMany<ReservationStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(ReservationStatusLog::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<NotificationLog, $this>
     */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'source' => ReservationSource::class,
            'payment_status' => ReservationPaymentStatus::class,
            'check_in' => 'date',
            'check_out' => 'date',
            'adults' => 'integer',
            'children' => 'integer',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'ota_blocked_at' => 'datetime',
            'policy_snapshot' => 'array',
        ];
    }
}
