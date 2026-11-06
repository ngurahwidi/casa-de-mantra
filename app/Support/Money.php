<?php

namespace App\Support;

use NumberFormatter;

/**
 * Formats decimal amounts in the property currency for the public site.
 *
 * The symbol comes from the currency code, never from the property name, and the
 * grouping follows the currency's common locale (id for IDR, en for the rest).
 */
final class Money
{
    /**
     * @var array<string, string>
     */
    private const SYMBOLS = [
        'IDR' => 'Rp',
        'USD' => 'US$',
        'EUR' => '€',
        'GBP' => '£',
        'AUD' => 'A$',
        'SGD' => 'S$',
        'MYR' => 'RM',
        'THB' => '฿',
        'JPY' => '¥',
    ];

    public static function format(string|int|float|null $amount, string $currency): string
    {
        $currency = strtoupper($currency);

        if ($amount === null || $amount === '') {
            return '—';
        }

        $value = (float) $amount;
        $hasCents = abs($value - round($value)) > 0.00001;

        $formatter = new NumberFormatter($currency === 'IDR' ? 'id' : 'en', NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $hasCents ? 2 : 0);

        $number = $formatter->format($value);
        $symbol = self::SYMBOLS[$currency] ?? null;

        return $symbol === null ? $currency.' '.$number : $symbol.$number;
    }
}
