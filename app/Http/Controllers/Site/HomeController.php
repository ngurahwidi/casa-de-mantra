<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Review;
use App\Models\RoomType;
use App\Services\Site\VillaCatalog;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(VillaCatalog $catalog): View
    {
        $villas = $catalog->published();

        return view('site.home', [
            'villas' => $villas,
            'prices' => $villas->mapWithKeys(
                fn (RoomType $villa) => [$villa->id => $catalog->lowestNightlyPrice($villa)],
            ),
            'heroPhoto' => $villas
                ->map(fn (RoomType $villa) => $villa->getFirstMedia(RoomType::GALLERY))
                ->filter()
                ->first(),
            'photos' => $villas
                ->flatMap(fn (RoomType $villa) => $villa->getMedia(RoomType::GALLERY)
                    ->map(fn ($media) => [
                        'url' => $media->getUrl(),
                        'alt' => $media->getCustomProperty('alt') ?: $villa->name,
                        'villa' => $villa->name,
                    ]))
                ->take(6)
                ->values(),
            'reviews' => Review::published()->latest()->limit(6)->get(),
            'faqs' => Faq::ordered()->limit(4)->get(),
        ]);
    }
}
