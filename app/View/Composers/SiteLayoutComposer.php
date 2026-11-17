<?php

namespace App\View\Composers;

use App\Models\PropertyProfile;
use App\Services\Site\HomepageHero;
use App\Services\Site\PropertySchema;
use App\Services\Site\VillaCatalog;
use App\Support\WhatsApp;
use Illuminate\View\View;

/**
 * Shares the property profile, default SEO data and WhatsApp link with public views.
 *
 * The default share image is the homepage banner, or the first villa photo without one
 * (HomepageHero); villa pages pass their own photo as $ogImage.
 *
 * A missing profile row is not fatal: the public site still renders with the app
 * name so an unseeded installation does not crash on the home page.
 */
class SiteLayoutComposer
{
    public function __construct(
        private readonly VillaCatalog $catalog,
        private readonly PropertySchema $schema,
        private readonly HomepageHero $hero,
    ) {}

    public function compose(View $view): void
    {
        $profile = rescue(fn () => PropertyProfile::current(), null, false);
        $villas = $profile === null ? collect() : $this->catalog->published();

        $shareImage = $this->hero->resolve($profile, $villas)->banner;

        $view->with([
            'profile' => $profile,
            'seoDefaults' => [
                'title' => data_get($profile, 'seo_settings.meta_title') ?: $profile?->name ?: config('app.name'),
                'description' => data_get($profile, 'seo_settings.meta_description'),
                'image' => $shareImage?->getUrl(),
            ],
            'defaultSchema' => $profile === null
                ? null
                : $this->schema->forProfile($profile, $villas),
            'whatsappUrl' => WhatsApp::url($profile),
        ]);
    }
}
