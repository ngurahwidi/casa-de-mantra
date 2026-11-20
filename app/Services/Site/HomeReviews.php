<?php

namespace App\Services\Site;

use App\Models\Review;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads the guest words shown across the public site.
 *
 * Only published reviews that actually say something are considered for the inline cards:
 * the highest rated come first, with the newest review winning a tie.
 */
class HomeReviews
{
    /**
     * Published reviews with a written comment, for the inline cards.
     *
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

    /**
     * Every published review — with and without a comment — for the dedicated reviews page.
     *
     * @return Collection<int, Review>
     */
    public function all(): Collection
    {
        return Review::query()
            ->published()
            ->orderByDesc('rating')
            ->orderByDesc('created_at')
            ->get();
    }
}
