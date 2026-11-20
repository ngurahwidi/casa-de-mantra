<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'guest_name',
    'rating',
    'comment',
    'source',
    'is_published',
])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory, HasUuids;

    /**
     * Shown when a review has no written comment (empty string or null).
     */
    public const DEFAULT_COMMENT = 'A charming homestay with genuine Balinese warmth, perfect for a calm and easy stay in Nusa Dua.';

    /**
     * @param  Builder<Review>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * The comment to display, falling back to a default when the review has none.
     */
    public function displayComment(): string
    {
        return filled($this->comment) ? $this->comment : self::DEFAULT_COMMENT;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_published' => 'boolean',
        ];
    }
}
