<?php

namespace App\Support;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The responsive WebP renditions generated for every photo shown on the public site:
 * villa galleries and the homepage banner.
 *
 * Originals smaller than a rendition keep their own size because Fit::Max never upscales.
 */
final class PhotoRenditions
{
    /**
     * Widths, in pixels, of the WebP renditions served to the public site.
     *
     * @var list<int>
     */
    public const WIDTHS = [480, 800, 1280, 1920];

    private const QUALITY = 80;

    /**
     * Height limit for the renditions. It is never reached, so the width alone decides the size.
     */
    private const MAX_HEIGHT = 10000;

    /**
     * Registers the renditions for one media collection; call it from registerMediaConversions().
     */
    public static function register(HasMedia $model, string $collection): void
    {
        foreach (self::WIDTHS as $width) {
            $model->addMediaConversion(self::conversion($width))
                ->performOnCollections($collection)
                ->fit(Fit::Max, $width, self::MAX_HEIGHT)
                ->quality(self::QUALITY)
                ->format('webp');
        }
    }

    public static function conversion(int $width): string
    {
        return "webp-{$width}";
    }

    /**
     * The srcset of the renditions generated so far, empty while they are still queued.
     */
    public static function srcset(Media $media): string
    {
        return collect(self::WIDTHS)
            ->filter(fn (int $width): bool => $media->hasGeneratedConversion(self::conversion($width)))
            ->map(fn (int $width): string => $media->getUrl(self::conversion($width))." {$width}w")
            ->implode(', ');
    }
}
