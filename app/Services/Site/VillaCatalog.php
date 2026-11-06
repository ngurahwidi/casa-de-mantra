<?php

namespace App\Services\Site;

use App\Models\CancellationPolicy;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read model for the public villa pages.
 *
 * Only published room types are exposed and all pricing is read from active rate
 * plans, so the public site can never show a draft villa or an inactive price.
 */
class VillaCatalog
{
    /**
     * @return Collection<int, RoomType>
     */
    public function published(): Collection
    {
        return RoomType::query()
            ->published()
            ->with(['media', 'amenities', 'ratePlans.rateRules', 'ratePlans.cancellationPolicy'])
            ->orderBy('name')
            ->get();
    }

    public function findPublished(string $slug): RoomType
    {
        return RoomType::query()
            ->published()
            ->where('slug', $slug)
            ->with(['media', 'amenities', 'ratePlans.rateRules', 'ratePlans.cancellationPolicy'])
            ->firstOrFail();
    }

    /**
     * The lowest nightly price that can currently be sold for the villa.
     */
    public function lowestNightlyPrice(RoomType $roomType): ?string
    {
        return $roomType->ratePlans
            ->where('is_active', true)
            ->flatMap(fn (RatePlan $ratePlan) => $ratePlan->rateRules)
            ->min('price_per_night');
    }

    public function activeRatePlan(RoomType $roomType): ?RatePlan
    {
        return $roomType->ratePlans->firstWhere('is_active', true);
    }

    public function policy(RoomType $roomType): ?CancellationPolicy
    {
        return $this->activeRatePlan($roomType)?->cancellationPolicy;
    }
}
