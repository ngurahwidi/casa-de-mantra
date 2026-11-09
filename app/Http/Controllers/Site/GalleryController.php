<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Services\Site\VillaCatalog;
use Illuminate\View\View;

class GalleryController extends Controller
{
    public function __invoke(VillaCatalog $catalog): View
    {
        $villas = $catalog->published()
            ->map(fn (RoomType $villa) => [
                'villa' => $villa,
                'photos' => $villa->getMedia(RoomType::GALLERY)
                    ->map(fn ($media) => [
                        'media' => $media,
                        'alt' => $media->getCustomProperty('alt') ?: $villa->name,
                    ])
                    ->values(),
            ])
            ->filter(fn (array $group) => $group['photos']->isNotEmpty())
            ->values();

        return view('site.gallery', [
            'title' => 'Gallery',
            'description' => 'Browse photos of the villas, gardens, pools, and bedrooms before you book your stay.',
            'groups' => $villas,
        ]);
    }
}
