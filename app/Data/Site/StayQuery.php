<?php

namespace App\Data\Site;

use App\Models\RoomType;
use Carbon\CarbonImmutable;

/**
 * The dates and party size a guest searched for on the public site.
 *
 * This only validates that the input is a real date range with a positive guest count,
 * so a malformed query string is ignored instead of reaching the pricing service.
 * Whether the dates are inside the booking window is decided by StaySearch.
 */
final readonly class StayQuery
{
    public function __construct(
        public CarbonImmutable $checkIn,
        public CarbonImmutable $checkOut,
        public int $guests,
    ) {}

    /**
     * The query from raw request input, or null when it is not a usable date range.
     */
    public static function make(mixed $checkIn, mixed $checkOut, mixed $guests, int $maxGuests): ?self
    {
        if (! is_string($checkIn) || ! is_string($checkOut) || ! self::isDate($checkIn) || ! self::isDate($checkOut)) {
            return null;
        }

        if ($checkOut <= $checkIn) {
            return null;
        }

        $guests = filter_var($guests, FILTER_VALIDATE_INT);

        if ($guests === false || $guests < 1) {
            return null;
        }

        return new self(
            CarbonImmutable::parse($checkIn),
            CarbonImmutable::parse($checkOut),
            min($guests, $maxGuests),
        );
    }

    public function nights(): int
    {
        return (int) $this->checkIn->diffInDays($this->checkOut);
    }

    /**
     * The search as query parameters for the home page.
     *
     * @return array{check_in: string, check_out: string, guests: int}
     */
    public function query(): array
    {
        return [
            'check_in' => $this->checkIn->toDateString(),
            'check_out' => $this->checkOut->toDateString(),
            'guests' => $this->guests,
        ];
    }

    /**
     * Splits the party into the adults and children the villa page search expects.
     *
     * @return array{0: int, 1: int}
     */
    public function partyFor(RoomType $villa): array
    {
        $adults = min($this->guests, $villa->max_adults);

        return [$adults, $this->guests - $adults];
    }

    /**
     * The selected stay as query parameters for a villa page, ready to prefill its widget.
     *
     * @return array{check_in: string, check_out: string, adults: int, children: int}
     */
    public function villaQuery(RoomType $villa): array
    {
        [$adults, $children] = $this->partyFor($villa);

        return [
            'check_in' => $this->checkIn->toDateString(),
            'check_out' => $this->checkOut->toDateString(),
            'adults' => $adults,
            'children' => $children,
        ];
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
