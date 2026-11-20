<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Review;
use App\Services\Site\HomeReviews;
use App\Services\Site\VillaCatalog;
use Illuminate\View\View;

class AboutController extends Controller
{
    public function __invoke(VillaCatalog $catalog, HomeReviews $homeReviews): View
    {
        $villas = $catalog->published();

        return view('site.about', [
            'title' => 'About',
            'description' => 'Meet the villa and the small team who look after it, and see what is already here for your stay.',
            'page' => Page::published()->where('slug', Page::ABOUT)->first(),
            'villas' => $villas,
            'amenities' => $villas
                ->flatMap(fn ($villa) => $villa->amenities)
                ->unique('id')
                ->sortBy('name')
                ->values(),
            'reviews' => $homeReviews->select()
                ->sortByDesc(fn (Review $review) => $review->created_at?->getTimestamp() ?? 0)
                ->take(3)
                ->values(),
            'allReviews' => $homeReviews->all(),
        ]);
    }
}
