<?php

namespace App\Services\Site;

use App\Enums\PropertyType;
use App\Models\PropertyProfile;
use App\Models\RoomType;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Builds the schema.org payload for the property from property_profiles.
 *
 * A villa is described as VacationRental and the other property types as
 * LodgingBusiness, both of which search engines treat as accommodation.
 */
class PropertySchema
{
    public function __construct(private readonly VillaCatalog $catalog) {}

    /**
     * @param  Collection<int, RoomType>  $villas
     * @return array<string, mixed>
     */
    public function forProfile(PropertyProfile $profile, Collection $villas, ?string $pageUrl = null): array
    {
        $images = $villas
            ->flatMap(fn (RoomType $villa) => $villa->getMedia(RoomType::GALLERY))
            ->map(fn ($media) => $media->getUrl())
            ->take(8)
            ->values()
            ->all();

        $amenities = $villas
            ->flatMap(fn (RoomType $villa) => $villa->amenities)
            ->pluck('name')
            ->unique()
            ->sort()
            ->values();

        $lowestPrice = $villas
            ->map(fn (RoomType $villa) => $this->catalog->lowestNightlyPrice($villa))
            ->filter()
            ->min();

        $description = data_get($profile, 'seo_settings.meta_description');

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $profile->type === PropertyType::Villa ? 'VacationRental' : 'LodgingBusiness',
            'name' => $profile->name,
            'description' => $description,
            'url' => $pageUrl ?? route('home'),
            'image' => $images,
            'telephone' => $profile->whatsapp,
            'email' => $profile->email,
            'checkinTime' => $profile->checkin_time,
            'checkoutTime' => $profile->checkout_time,
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $profile->address,
            ]),
            'geo' => $profile->lat !== null && $profile->lng !== null
                ? [
                    '@type' => 'GeoCoordinates',
                    'latitude' => (float) $profile->lat,
                    'longitude' => (float) $profile->lng,
                ]
                : null,
            'amenityFeature' => $amenities
                ->map(fn (string $name) => [
                    '@type' => 'LocationFeatureSpecification',
                    'name' => $name,
                    'value' => true,
                ])
                ->values()
                ->all(),
            'priceRange' => $lowestPrice === null
                ? null
                : 'From '.Money::format($lowestPrice, $profile->currency).' per night',
        ];

        return array_filter($schema, fn ($value) => $value !== null && $value !== [] && $value !== '');
    }
}
