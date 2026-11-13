<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationLogStatus;
use App\Enums\ReservationStatus;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property NotificationChannel $channel
 * @property NotificationLogStatus $status
 * @property Carbon|null $sent_at
 */
#[Fillable([
    'reservation_id',
    'channel',
    'template',
    'dedupe_key',
    'status',
    'error_message',
    'sent_at',
])]
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory, HasUuids, MassPrunable;

    /**
     * Logs older than the retention period whose reservation is finished, pruned by the scheduled
     * model:prune (T9.1).
     *
     * A reservation still in progress keeps its logs, because their dedupe_key is what stops a
     * reminder about its current status from being queued again. A log still queued is kept for
     * its job. Expired counts as finished: a reactivation starts a new status log, so reminders
     * about it are keyed afresh.
     *
     * @return Builder<NotificationLog>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where('created_at', '<', now()->subDays((int) config('retention.notification_log_days')))
            ->where('status', '!=', NotificationLogStatus::Queued)
            ->whereHas('reservation', fn (Builder $query) => $query->whereIn('status', [
                ReservationStatus::Completed,
                ReservationStatus::Rejected,
                ReservationStatus::Cancelled,
                ReservationStatus::Expired,
            ]));
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationLogStatus::class,
            'sent_at' => 'datetime',
        ];
    }
}
