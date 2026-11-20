<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Services\Site\HomeReviews;
use App\Services\Site\VillaCatalog;
use Illuminate\View\View;

class ReviewsController extends Controller
{
    public function __invoke(VillaCatalog $catalog, HomeReviews $homeReviews): View
    {
        $villas = $catalog->published();

        return view('site.reviews', [
            'title' => 'Guest reviews',
            'description' => 'Read what guests remember from their stays, and explore the villas and amenities that come with booking direct.',
            'reviews' => $homeReviews->all(),
            'villas' => $villas,
            'prices' => $villas->mapWithKeys(
                fn (RoomType $villa) => [$villa->id => $catalog->lowestNightlyPrice($villa)],
            ),
            'amenities' => $villas
                ->flatMap(fn (RoomType $villa) => $villa->amenities)
                ->unique('id')
                ->sortBy('name')
                ->values(),
        ]);
    }
}
