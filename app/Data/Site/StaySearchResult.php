<?php

namespace App\Data\Site;

use App\Data\Pricing\PriceQuote;
use App\Models\CancellationPolicy;

/**
 * The answer to a guest's date search on the public site.
 *
 * An available stay carries its price and the cancellation policy it is sold under.
 * An unavailable stay carries a guest-facing reason and, when one exists, the nearest
 * stay of the same length that can be booked instead.
 */
final readonly class StaySearchResult
{
    private function __construct(
        public bool $isAvailable,
        public ?PriceQuote $quote,
        public ?CancellationPolicy $policy,
        public ?string $message,
        public ?PriceQuote $alternative,
    ) {}

    public static function available(PriceQuote $quote, ?CancellationPolicy $policy): self
    {
        return new self(true, $quote, $policy, null, null);
    }

    public static function unavailable(string $message, ?PriceQuote $alternative = null): self
    {
        return new self(false, null, null, $message, $alternative);
    }
}
