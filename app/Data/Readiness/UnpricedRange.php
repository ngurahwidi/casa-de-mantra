<?php

namespace App\Data\Readiness;

use Carbon\CarbonImmutable;

/**
 * A run of consecutive nights that have no price, so they cannot be booked.
 */
final readonly class UnpricedRange
{
    /**
     * @param  string  $from  first night of the run, Y-m-d
     * @param  string  $to  last night of the run, Y-m-d
     */
    public function __construct(
        public string $from,
        public string $to,
    ) {}

    public function nights(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /**
     * As "12 Dec 2026 – 20 Dec 2026 (9 nights)", or "12 Dec 2026 (1 night)" for a single night.
     */
    public function label(): string
    {
        $nights = trans_choice(':count night|:count nights', $this->nights());

        return $this->from === $this->to
            ? CarbonImmutable::parse($this->from)->format('j M Y')." ({$nights})"
            : CarbonImmutable::parse($this->from)->format('j M Y').' – '.CarbonImmutable::parse($this->to)->format('j M Y')." ({$nights})";
    }
}
