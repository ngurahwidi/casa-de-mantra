<?php

namespace App\Services\Site;

use App\Models\Review;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads the guest words shown on the home page.
 *
 * Only published reviews that actually say something are considered: the highest rated come
 * first, with the newest review winning a tie.
 */
class HomeReviews
{
    /**
     * @return Collection<int, Review>
     */
    public function select(): Collection
    {
        return Review::query()
            ->published()
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->orderByDesc('rating')
            ->orderByDesc('created_at')
            ->get();
    }
}
