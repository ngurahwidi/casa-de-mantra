<?php

namespace App\Models;

use App\Enums\ReservationSourceCategory;
use App\Policies\ReservationSourcePolicy;
use Database\Factories\ReservationSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Where a reservation was booked: the website, an OTA, or directly with the guest (phone, walk-in).
 *
 * The website source is built in: requests from the booking form get it, and it cannot be
 * changed or removed. Admins add the other sources and pick an active one when entering a
 * manual reservation. A source with reservations cannot be deleted, only set inactive.
 *
 * `code` is derived from the name when the source is created and never changes afterwards; it
 * is stored as reservations.external_source next to an OTA booking number.
 *
 * @property string $name
 * @property string $code
 * @property ReservationSourceCategory $category
 * @property bool $is_active
 */
#[Fillable(['name', 'category', 'is_active'])]
#[UsePolicy(ReservationSourcePolicy::class)]
class ReservationSource extends Model
{
    /** @use HasFactory<ReservationSourceFactory> */
    use HasFactory, HasUuids;

    /**
     * The built-in source of requests from the booking form on this website.
     */
    public static function website(): self
    {
        return static::query()->where('category', ReservationSourceCategory::Website)->sole();
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function isWebsite(): bool
    {
        return $this->category === ReservationSourceCategory::Website;
    }

    public function isOta(): bool
    {
        return $this->category === ReservationSourceCategory::Ota;
    }

    /**
     * Reservations restrict deletion at the database level; such a source is set inactive instead.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->isWebsite() && ! $this->reservations()->exists();
    }

    /**
     * @param  Builder<ReservationSource>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The sources an admin can choose when entering a reservation by hand.
     *
     * @param  Builder<ReservationSource>  $query
     */
    public function scopeForManualEntry(Builder $query): void
    {
        $query->where('is_active', true)->where('category', '!=', ReservationSourceCategory::Website);
    }

    /**
     * A lower-case code made from the name that no other source has, e.g. "booking_com".
     */
    public static function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 40, '');
        $base = $base === '' ? 'source' : $base;

        $code = $base;
        for ($suffix = 2; static::query()->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}_{$suffix}";
        }

        return $code;
    }

    protected static function booted(): void
    {
        static::creating(function (ReservationSource $source): void {
            $source->code ??= static::uniqueCode($source->name);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ReservationSourceCategory::class,
            'is_active' => 'boolean',
        ];
    }
}
