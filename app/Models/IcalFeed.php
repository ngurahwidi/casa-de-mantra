<?php

namespace App\Models;

use App\Enums\IcalChannel;
use App\Enums\IcalDirection;
use App\Policies\IcalFeedPolicy;
use Database\Factories\IcalFeedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * last_status is null until the first sync, STATUS_OK after a sync that worked, and otherwise
 * the reason the latest sync failed.
 *
 * @property IcalChannel $channel
 * @property IcalDirection $direction
 * @property string|null $last_status
 */
#[Fillable([
    'room_type_id',
    'channel',
    'direction',
    'url',
    'export_token',
    'last_synced_at',
    'last_status',
])]
#[UsePolicy(IcalFeedPolicy::class)]
class IcalFeed extends Model
{
    /** @use HasFactory<IcalFeedFactory> */
    use HasFactory, HasUuids;

    public const STATUS_OK = 'ok';

    /**
     * An export feed is served at a link only the OTA knows, so the token is made with the feed.
     */
    protected static function booted(): void
    {
        static::creating(function (IcalFeed $feed): void {
            if ($feed->direction === IcalDirection::Export && blank($feed->export_token)) {
                $feed->export_token = static::newExportToken();
            }
        });
    }

    /**
     * Random and lower case only, so it reads the same however a calendar app cases the link.
     */
    public static function newExportToken(): string
    {
        return Str::lower(Str::random(40));
    }

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
     * Import feeds whose latest sync failed. Feeds that have never synced are not failing yet.
     *
     * @param  Builder<IcalFeed>  $query
     */
    public function scopeFailing(Builder $query): void
    {
        $query->where('direction', IcalDirection::Import)
            ->whereNotNull('last_status')
            ->where('last_status', '!=', self::STATUS_OK);
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
