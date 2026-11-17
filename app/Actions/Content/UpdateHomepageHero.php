<?php

namespace App\Actions\Content;

use App\Models\PropertyProfile;
use Illuminate\Http\UploadedFile;

/**
 * Saves the homepage hero: its title, subtitle and banner.
 *
 * Owners and staff both edit the hero (it is content), although only the owner edits the rest
 * of the property profile it is stored on. Empty text is stored as null so the site falls back
 * to the built-in wording. A new banner replaces the current one (single-file collection); the
 * alt text belongs to the new banner, or to the current one when no banner is uploaded.
 *
 * There is no transaction: a file cannot be rolled back, so a failed upload keeps the saved text.
 */
class UpdateHomepageHero
{
    public function handle(
        PropertyProfile $profile,
        ?string $title,
        ?string $subtitle,
        ?string $bannerAlt,
        ?UploadedFile $banner = null,
    ): void {
        $profile->update([
            'hero_title' => self::clean($title),
            'hero_subtitle' => self::clean($subtitle),
        ]);

        if ($banner !== null) {
            $profile
                ->addMedia($banner)
                ->usingName(pathinfo($banner->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties(['alt' => self::clean($bannerAlt)])
                ->toMediaCollection(PropertyProfile::HERO);

            return;
        }

        $current = $profile->getFirstMedia(PropertyProfile::HERO);

        if ($current !== null) {
            $current->setCustomProperty('alt', self::clean($bannerAlt));
            $current->save();
        }
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
