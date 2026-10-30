<?php

namespace App\Services\Reservation;

use App\Models\Reservation;
use RuntimeException;

/**
 * Public reservation codes that guests type in to check their booking.
 *
 * Codes look like RSV-7KQ2MX9P: a fixed prefix and a random part, not sequential, so one
 * code says nothing about another. The random part uses upper-case letters and digits
 * without look-alikes (0/O, 1/I/L), which gives about 8.5 × 10^11 combinations for 8
 * characters. The column compares case-sensitively, so input should be upper-cased
 * before lookup.
 */
class ReservationCodeGenerator
{
    public const PREFIX = 'RSV-';

    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    private const MAX_ATTEMPTS = 5;

    /**
     * A code not used by any reservation yet. The unique index still guards against a
     * concurrent insert of the same code.
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = static::random();

            if (! Reservation::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique reservation code.');
    }

    public static function random(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $code = self::PREFIX;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }
}
