<?php

namespace App\Data\Site;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What the homepage hero shows, with the built-in wording and villa photo already filled in
 * for anything the admin left empty.
 */
final readonly class HomepageHeroContent
{
    public function __construct(
        public string $title,
        public string $subtitle,
        public ?Media $banner,
        public string $bannerAlt,
        public bool $hasCustomTitle,
        public bool $hasCustomSubtitle,
        public bool $hasCustomBanner,
    ) {}
}
