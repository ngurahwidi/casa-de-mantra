<?php

namespace App\Services\Site;

use App\Data\Site\HomepageHeroContent;
use App\Models\PropertyProfile;
use App\Models\RoomType;
use Illuminate\Support\Collection;

/**
 * Resolves the homepage hero from what the admin set on the "Homepage" page.
 *
 * Every part is optional: an empty title or subtitle falls back to the built-in wording, and
 * without an uploaded banner the first photo of the published villas is used, as before the
 * hero became editable.
 */
class HomepageHero
{
    public const DEFAULT_TITLE = 'A private villa, entirely yours.';

    public const DEFAULT_SUBTITLE = 'Slow mornings, long afternoons by the pool, and evenings that stretch a little further. Book directly with the people who look after the villa.';

    public const TITLE_MAX_LENGTH = 80;

    public const SUBTITLE_MAX_LENGTH = 250;

    /**
     * @param  Collection<int, RoomType>  $villas  published villas, with their media loaded
     */
    public function resolve(?PropertyProfile $profile, Collection $villas): HomepageHeroContent
    {
        $customBanner = $profile?->getFirstMedia(PropertyProfile::HERO);

        $banner = $customBanner ?? $villas
            ->map(fn (RoomType $villa) => $villa->getFirstMedia(RoomType::GALLERY))
            ->filter()
            ->first();

        $title = $profile?->hero_title;
        $subtitle = $profile?->hero_subtitle;

        return new HomepageHeroContent(
            title: filled($title) ? $title : self::DEFAULT_TITLE,
            subtitle: filled($subtitle) ? $subtitle : self::DEFAULT_SUBTITLE,
            banner: $banner,
            bannerAlt: $banner?->getCustomProperty('alt') ?: ($profile->name ?? 'Villa'),
            hasCustomTitle: filled($title),
            hasCustomSubtitle: filled($subtitle),
            hasCustomBanner: $customBanner !== null,
        );
    }
}
