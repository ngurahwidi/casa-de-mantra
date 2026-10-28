<?php

namespace App\Models;

use App\Enums\OutboxEventStatus;
use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'type',
    'payload',
    'status',
    'processed_at',
])]
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory, HasUuids;

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
