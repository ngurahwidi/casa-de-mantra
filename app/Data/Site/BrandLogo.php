<?php

namespace App\Data\Site;

/**
 * A logo to place on a dark background (site footer, email header).
 *
 * Without a dedicated dark-background version the regular logo is used, and $onLightBadge
 * asks the view to put it on a light badge so it stays readable.
 */
final readonly class BrandLogo
{
    public function __construct(
        public string $url,
        public bool $onLightBadge,
    ) {}
}
