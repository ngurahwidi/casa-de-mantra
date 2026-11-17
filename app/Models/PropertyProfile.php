<?php

namespace App\Models;

use App\Data\Site\BrandLogo;
use App\Enums\PropertyType;
use App\Policies\SystemSettingPolicy;
use App\Support\PhotoRenditions;
use Database\Factories\PropertyProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property PropertyType $type
 * @property array<string, mixed>|null $seo_settings
 * @property array<string, mixed>|null $theme
 * @property array<string, mixed>|null $bank_account_info
 */
#[Fillable([
    'type',
    'name',
    'timezone',
    'currency',
    'checkin_time',
    'checkout_time',
    'whatsapp',
    'email',
    'address',
    'lat',
    'lng',
    'seo_settings',
    'theme',
    'logo_path',
    'logo_dark_path',
    'hero_title',
    'hero_subtitle',
    'bank_account_info',
    'payment_window_hours',
    'pending_reminder_hours',
    'confirm_on_deposit',
])]
#[UsePolicy(SystemSettingPolicy::class)]
class PropertyProfile extends Model implements HasMedia
{
    /** @use HasFactory<PropertyProfileFactory> */
    use HasFactory, InteractsWithMedia;

    public const ID = 1;

    /** Media collection holding the homepage banner; a new upload replaces the old one. */
    public const HERO = 'hero';

    /** Disk and directory of the uploaded logos. */
    public const LOGO_DISK = 'public';

    public const LOGO_DIRECTORY = 'branding';

    /** @var list<string> */
    private const LOGO_COLUMNS = ['logo_path', 'logo_dark_path'];

    public const CACHE_KEY = 'property_profile.current';

    public $incrementing = false;

    /**
     * MySQL JSON columns cannot carry a literal default, so it lives here.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'id' => self::ID,
        'seo_settings' => '[]',
        'theme' => '[]',
        'bank_account_info' => '[]',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetCurrent());
        static::deleted(fn () => static::forgetCurrent());

        // Filament leaves replaced or removed uploads on the disk, so the old logo file goes here.
        static::updated(function (PropertyProfile $profile): void {
            foreach (self::LOGO_COLUMNS as $column) {
                $old = $profile->getOriginal($column);

                if ($profile->wasChanged($column) && filled($old)) {
                    Storage::disk(self::LOGO_DISK)->delete($old);
                }
            }
        });
    }

    /**
     * The single property profile, cached until it is saved or deleted.
     *
     * Only the raw attributes are cached: the cache refuses to unserialize objects
     * (cache.serializable_classes = false), so the model is rebuilt on every read.
     */
    public static function current(): static
    {
        $attributes = Cache::rememberForever(
            static::CACHE_KEY,
            fn () => static::query()->findOrFail(static::ID)->getAttributes(),
        );

        return static::query()->newModelInstance()->newFromBuilder($attributes);
    }

    public static function forgetCurrent(): void
    {
        Cache::forget(static::CACHE_KEY);
    }

    public function logoUrl(): ?string
    {
        return self::publicUrl($this->logo_path);
    }

    public function darkLogoUrl(): ?string
    {
        return self::publicUrl($this->logo_dark_path);
    }

    /**
     * The logo for a dark background: the dark version, or the regular logo on a light badge.
     */
    public function logoForDarkBackground(): ?BrandLogo
    {
        if (($dark = $this->darkLogoUrl()) !== null) {
            return new BrandLogo($dark, onLightBadge: false);
        }

        $logo = $this->logoUrl();

        return $logo === null ? null : new BrandLogo($logo, onLightBadge: true);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::HERO)->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        PhotoRenditions::register($this, self::HERO);
    }

    /**
     * The sender of outgoing email; without a property email, MAIL_FROM_ADDRESS is used.
     */
    public function mailSender(): ?Address
    {
        return blank($this->email) ? null : new Address($this->email, $this->name);
    }

    private static function publicUrl(?string $path): ?string
    {
        return blank($path) ? null : Storage::disk(self::LOGO_DISK)->url($path);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PropertyType::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'seo_settings' => 'array',
            'theme' => 'array',
            'bank_account_info' => 'array',
            'payment_window_hours' => 'integer',
            'pending_reminder_hours' => 'integer',
            'confirm_on_deposit' => 'boolean',
        ];
    }
}
