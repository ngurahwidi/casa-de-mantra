<?php

namespace App\View\Composers;

use App\Models\PropertyProfile;
use App\Models\RoomType;
use App\Services\Site\PropertySchema;
use App\Services\Site\VillaCatalog;
use Illuminate\View\View;

/**
 * Shares the property profile, default SEO data and WhatsApp link with public views.
 *
 * A missing profile row is not fatal: the public site still renders with the app
 * name so an unseeded installation does not crash on the home page.
 */
class SiteLayoutComposer
{
    public function __construct(
        private readonly VillaCatalog $catalog,
        private readonly PropertySchema $schema,
    ) {}

    public function compose(View $view): void
    {
        $profile = rescue(fn () => PropertyProfile::current(), null, false);
        $villas = $profile === null ? collect() : $this->catalog->published();

        $firstPhoto = $villas
            ->map(fn (RoomType $villa) => $villa->getFirstMedia(RoomType::GALLERY))
            ->filter()
            ->first();

        $view->with([
            'profile' => $profile,
            'seoDefaults' => [
                'title' => data_get($profile, 'seo_settings.meta_title') ?: $profile?->name ?: config('app.name'),
                'description' => data_get($profile, 'seo_settings.meta_description'),
                'image' => $firstPhoto?->getUrl(),
            ],
            'defaultSchema' => $profile === null
                ? null
                : $this->schema->forProfile($profile, $villas),
            'whatsappUrl' => $this->whatsappUrl($profile),
        ]);
    }

    private function whatsappUrl(?PropertyProfile $profile): ?string
    {
        $number = preg_replace('/\D+/', '', (string) $profile?->whatsapp);

        if ($number === '') {
            return null;
        }

        $message = rawurlencode(sprintf('Hello %s, I would like to ask about a stay.', $profile->name));

        return "https://wa.me/{$number}?text={$message}";
    }
}
