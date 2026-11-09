<?php

namespace App\Models;

use App\Policies\SystemSettingPolicy;
use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'name',
    'slug',
    'description',
    'max_adults',
    'max_children',
    'bedrooms',
    'bathrooms',
    'size_m2',
    'is_published',
])]
#[UsePolicy(SystemSettingPolicy::class)]
class RoomType extends Model implements HasMedia
{
    /** @use HasFactory<RoomTypeFactory> */
    use HasFactory, HasUuids, InteractsWithMedia;

    public const GALLERY = 'gallery';

    /**
     * Widths, in pixels, of the WebP renditions served to the public site.
     *
     * @var list<int>
     */
    public const PHOTO_WIDTHS = [480, 800, 1280, 1920];

    private const PHOTO_QUALITY = 80;

    /**
     * Height limit for the renditions. It is never reached, so the width alone decides the size.
     */
    private const PHOTO_MAX_HEIGHT = 10000;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::GALLERY);
    }

    /**
     * Originals smaller than a rendition keep their own size because Fit::Max never upscales.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (self::PHOTO_WIDTHS as $width) {
            $this->addMediaConversion(self::photoConversion($width))
                ->performOnCollections(self::GALLERY)
                ->fit(Fit::Max, $width, self::PHOTO_MAX_HEIGHT)
                ->quality(self::PHOTO_QUALITY)
                ->format('webp');
        }
    }

    public static function photoConversion(int $width): string
    {
        return "webp-{$width}";
    }

    /**
     * The srcset of the renditions generated so far, empty while they are still queued.
     */
    public static function photoSrcset(Media $media): string
    {
        return collect(self::PHOTO_WIDTHS)
            ->filter(fn (int $width): bool => $media->hasGeneratedConversion(self::photoConversion($width)))
            ->map(fn (int $width): string => $media->getUrl(self::photoConversion($width))." {$width}w")
            ->implode(', ');
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * @return HasMany<RatePlan, $this>
     */
    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }

    /**
     * The only rate plan sold in phase 1, created when the base price is first set.
     *
     * @return HasOne<RatePlan, $this>
     */
    public function nonRefundableRatePlan(): HasOne
    {
        return $this->hasOne(RatePlan::class)->where('name', CancellationPolicy::NON_REFUNDABLE);
    }

    /**
     * @return HasMany<IcalFeed, $this>
     */
    public function icalFeeds(): HasMany
    {
        return $this->hasMany(IcalFeed::class);
    }

    /**
     * @return HasMany<ReservationRoom, $this>
     */
    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /**
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'room_type_amenity');
    }

    /**
     * @param  Builder<RoomType>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Units, rate plans and reservations restrict deletion at the database level.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->units()->exists()
            && ! $this->ratePlans()->exists()
            && ! $this->reservationRooms()->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'size_m2' => 'integer',
            'is_published' => 'boolean',
        ];
    }
}
