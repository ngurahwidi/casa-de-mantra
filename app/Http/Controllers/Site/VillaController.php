<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Services\Site\VillaCatalog;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VillaController extends Controller
{
    public function index(VillaCatalog $catalog): View
    {
        $villas = $catalog->published();

        return view('site.villas.index', [
            'title' => 'Villas',
            'description' => 'Private villas for direct booking. Compare the space, the setting, and the nightly rate before you send a request.',
            'villas' => $villas,
            'prices' => $villas->mapWithKeys(
                fn (RoomType $villa) => [$villa->id => $catalog->lowestNightlyPrice($villa)],
            ),
        ]);
    }

    public function show(string $slug, VillaCatalog $catalog): View
    {
        $villa = $catalog->findPublished($slug);
        $others = $catalog->published()
            ->where('id', '!=', $villa->id)
            ->take(3)
            ->values();

        return view('site.villas.show', [
            'title' => $villa->name,
            'description' => Str::limit(strip_tags($villa->description ?? ''), 155) ?: null,
            'ogImage' => $villa->getFirstMediaUrl(RoomType::GALLERY) ?: null,
            'villa' => $villa,
            'price' => $catalog->lowestNightlyPrice($villa),
            'policy' => $catalog->policy($villa),
            'ratePlan' => $catalog->activeRatePlan($villa),
            'others' => $others,
            'otherPrices' => $others->mapWithKeys(
                fn (RoomType $other) => [$other->id => $catalog->lowestNightlyPrice($other)],
            ),
        ]);
    }
}
