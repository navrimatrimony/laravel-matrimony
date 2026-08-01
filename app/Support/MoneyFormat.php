<?php

namespace App\Support;

/**
 * The ONE place a rupee amount is turned into text for a human to read.
 *
 * Two frozen workspace rules meet here, and `number_format()` alone satisfies
 * only the first of them:
 *
 *  1. Latin digits 0-9, always, in either language. Satisfied by construction —
 *     nothing here is locale-aware, so there is no formatter left to decide on
 *     its own that `mr` means Devanagari numerals. (`LatinDigits` repairs a
 *     string that already went wrong; this prevents the string.)
 *  2. Indian comma grouping. `number_format()` breaks this: it writes
 *     ₹100,000 where an Indian reader expects ₹1,00,000. Below a lakh the two
 *     agree, which is exactly why the mistake survives review — it only shows
 *     itself on the larger figures, and a marriage success fee is precisely
 *     the kind of number that reaches a lakh.
 *
 * Grouping logic is the one already proven in `components/income-engine`:
 * last three digits, then pairs. Do not add a second money formatter — extend
 * this one.
 */
final class MoneyFormat
{
    /**
     * Null in, null out — quoted amounts are frequently absent, and "not quoted"
     * is a real answer that the caller words for itself. Never print ₹0 for it.
     */
    public static function amount(int|float|string|null $amount, string $currency = 'INR'): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $currency = strtoupper(trim($currency)) ?: 'INR';

        return ($currency === 'INR' ? '₹' : $currency.' ').self::grouped((float) $amount);
    }

    /**
     * Paise are shown only when there are paise: a fee of exactly ₹15,000 reads
     * worse as ₹15,000.00, and every fee this product quotes is a round figure.
     */
    private static function grouped(float $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);

        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;
        [$whole, $fraction] = array_pad(
            explode('.', number_format($value, $decimals, '.', '')),
            2,
            null,
        );

        if (strlen($whole) > 3) {
            $rest = substr($whole, 0, -3);
            $whole = strrev(implode(',', str_split(strrev($rest), 2))).','.substr($whole, -3);
        }

        return $sign.$whole.($fraction !== null ? '.'.$fraction : '');
    }
}
