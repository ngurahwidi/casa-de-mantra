<?php

namespace App\Models;

use App\Enums\OutboxEventStatus;
use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $payload
 * @property OutboxEventStatus $status
 * @property Carbon|null $processed_at
 */
#[Fillable([
    'type',
    'payload',
    'status',
    'processed_at',
])]
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory, HasUuids, MassPrunable;

    /**
     * Processed events older than the retention period, pruned by the scheduled model:prune
     * (T9.1). Pending and failed events are kept for a consumer to deal with.
     *
     * @return Builder<OutboxEvent>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where('status', OutboxEventStatus::Processed)
            ->where('processed_at', '<', now()->subDays((int) config('retention.outbox_event_days')));
    }

    /**
     * @param  Builder<OutboxEvent>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', OutboxEventStatus::Pending);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxEventStatus::class,
            'processed_at' => 'datetime',
        ];
    }
}
