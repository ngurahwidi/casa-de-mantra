<?php

namespace App\Models;

use App\Enums\PropertyType;
use Database\Factories\PropertyProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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
    'bank_account_info',
    'payment_window_hours',
    'pending_reminder_hours',
])]
class PropertyProfile extends Model
{
    /** @use HasFactory<PropertyProfileFactory> */
    use HasFactory;

    public const ID = 1;

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
    }

    /**
     * The single property profile, cached until it is saved or deleted.
     */
    public static function current(): static
    {
        return Cache::rememberForever(
            static::CACHE_KEY,
            fn () => static::query()->findOrFail(static::ID),
        );
    }

    public static function forgetCurrent(): void
    {
        Cache::forget(static::CACHE_KEY);
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
        ];
    }
}
