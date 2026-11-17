<?php

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'slug',
    'title',
    'body',
    'is_published',
])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, HasUuids;

    public const ABOUT = 'about';

    public const LOCATION = 'location';

    /**
     * Slugs whose content fills a dedicated site page instead of being listed as a policy.
     *
     * @var list<string>
     */
    public const SITE_PAGE_SLUGS = [self::ABOUT, self::LOCATION];

    /**
     * @param  Builder<Page>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopePolicies(Builder $query): void
    {
        $query->whereNotIn('slug', self::SITE_PAGE_SLUGS);
    }

    public function isPolicy(): bool
    {
        return ! in_array($this->slug, self::SITE_PAGE_SLUGS, true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }
}
