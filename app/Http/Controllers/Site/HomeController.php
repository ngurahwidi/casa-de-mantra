<?php

namespace App\Http\Controllers\Site;

use App\Data\Site\StayQuery;
use App\Data\Site\StaySearchResult;
use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\PropertyProfile;
use App\Models\Review;
use App\Models\RoomType;
use App\Services\Site\StaySearch;
use App\Services\Site\VillaCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request, VillaCatalog $catalog, StaySearch $search): View
    {
        $villas = $catalog->published();
        $maxGuests = max(1, (int) $villas->max(fn (RoomType $villa) => $villa->max_adults + $villa->max_children));

        $stayQuery = $villas->isEmpty() ? null : $this->stayQuery($request, $search, $maxGuests);
        $stays = [];

        if ($stayQuery !== null) {
            $stays = $villas->mapWithKeys(
                fn (RoomType $villa) => [$villa->id => $this->stayResult($search, $stayQuery, $villa)],
            )->all();

            $villas = $villas
                ->sortByDesc(fn (RoomType $villa) => $stays[$villa->id]->isAvailable)
                ->values();
        }

        return view('site.home', [
            'villas' => $villas,
            'prices' => $villas->mapWithKeys(
                fn (RoomType $villa) => [$villa->id => $catalog->lowestNightlyPrice($villa)],
            ),
            'stayQuery' => $stayQuery,
            'stays' => $stays,
            'maxGuests' => $maxGuests,
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

    /**
     * The dates from the query string when they are a usable stay inside the booking window.
     */
    private function stayQuery(Request $request, StaySearch $search, int $maxGuests): ?StayQuery
    {
        if (rescue(fn () => PropertyProfile::current(), null, false) === null) {
            return null;
        }

        $stayQuery = StayQuery::make(
            $request->query('check_in'),
            $request->query('check_out'),
            $request->query('guests', 2),
            $maxGuests,
        );

        if ($stayQuery === null
            || $stayQuery->checkIn < $search->today()
            || $stayQuery->checkOut > $search->lastCheckOut()) {
            return null;
        }

        return $stayQuery;
    }

    private function stayResult(StaySearch $search, StayQuery $stayQuery, RoomType $villa): StaySearchResult
    {
        [$adults, $children] = $stayQuery->partyFor($villa);

        return $search->search($villa, $stayQuery->checkIn, $stayQuery->checkOut, $adults, $children);
    }
}
