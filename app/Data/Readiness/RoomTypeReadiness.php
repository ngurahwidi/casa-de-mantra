<?php

namespace App\Data\Readiness;

use App\Models\RoomType;

/**
 * What a room type still needs before it may be published and sold, as the "Ready to sell?"
 * check sees it. A night without a price cannot be booked, so runs of unpriced nights count
 * as a failed check even when the room type has a base price.
 */
final readonly class RoomTypeReadiness
{
    /**
     * @param  bool  $hasCapacity  at least one adult fits
     * @param  bool  $hasPhoto  at least one photo in the gallery
     * @param  bool  $hasActiveUnit  at least one active unit to put guests in
     * @param  bool  $hasBasePrice  an active Non-Refundable rate plan linked to a policy
     * @param  list<UnpricedRange>  $unpricedRanges  runs of nights without a price within the checked window
     * @param  int  $nightsAhead  size of the checked window in nights
     */
    public function __construct(
        public RoomType $roomType,
        public bool $hasCapacity,
        public bool $hasPhoto,
        public bool $hasActiveUnit,
        public bool $hasBasePrice,
        public array $unpricedRanges,
        public int $nightsAhead,
    ) {}

    public function hasAllNightsPriced(): bool
    {
        return $this->unpricedRanges === [];
    }

    /**
     * Every mandatory check passes, so the room type may be published.
     */
    public function isComplete(): bool
    {
        return $this->hasCapacity
            && $this->hasPhoto
            && $this->hasActiveUnit
            && $this->hasBasePrice
            && $this->hasAllNightsPriced();
    }

    /**
     * @return list<string> what stands in the way of publishing, in plain words
     */
    public function failures(): array
    {
        $failures = [];

        if (! $this->hasCapacity) {
            $failures[] = 'set a capacity of at least one adult';
        }

        if (! $this->hasPhoto) {
            $failures[] = 'add at least one photo';
        }

        if (! $this->hasActiveUnit) {
            $failures[] = 'add at least one active unit';
        }

        if (! $this->hasBasePrice) {
            $failures[] = 'set a base price';
        } elseif (! $this->hasAllNightsPriced()) {
            $failures[] = 'price every night of the next '.$this->nightsAhead
                .' days; the first gap is '.$this->unpricedRanges[0]->label();
        }

        return $failures;
    }
}
