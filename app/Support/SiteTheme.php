<?php

namespace App\Support;

use App\Models\PropertyProfile;

/**
 * Resolves the brand colours of the public site from property_profiles.theme.
 *
 * Values are printed inside style tags, so anything that is not a hex colour is
 * replaced by the default palette.
 */
final class SiteTheme
{
    private const DEFAULT_PRIMARY = '#2F3B2B';

    private const DEFAULT_ACCENT = '#A7B59A';

    /**
     * @return array{primary: string, accent: string}
     */
    public static function colors(?PropertyProfile $profile): array
    {
        return [
            'primary' => self::hexColor(data_get($profile, 'theme.primary'), self::DEFAULT_PRIMARY),
            'accent' => self::hexColor(data_get($profile, 'theme.accent'), self::DEFAULT_ACCENT),
        ];
    }

    private static function hexColor(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : $default;
    }
}
