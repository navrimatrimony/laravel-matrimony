<?php

namespace App\Support;

/**
 * The ONE place a percentage is turned into text for a human to read — {@see MoneyFormat}'s sibling,
 * and written for the same two reasons.
 *
 *  1. Latin digits 0-9, always, in either language (frozen workspace rule). Satisfied by
 *     construction: nothing here is locale-aware, so there is no formatter left to decide on its own
 *     that `mr` means Devanagari numerals.
 *  2. No trailing zeros. "90%" is what a Suchak declared; "90.00%" is what a formatter did to it.
 *
 * WHY IT EXISTS (2026-08-05). `readablePercent()` had already been written privately FOUR times —
 * `SuchakCrossSuchakObligationService`, `SuchakReputationService`, `SuchakSuccessFeeTrancheService`
 * and, inline, in `SuchakMarketplaceChallengeService::declaredSharePayload()` — and the copies had
 * already drifted: two round to one decimal and two to two, so the same 12.25% reads as "12.3" on
 * one Suchak screen and "12.25" on the next. That is the frozen no-duplicate rule failing exactly
 * the way it says it fails, and the fix is a shared reader rather than a fifth copy. The four are
 * migration debt, deliberately not touched in the commit that created this class; do not add a
 * fifth.
 *
 * `$decimals` is a parameter and not a constant because the two roundings are both correct for what
 * they describe: a DERIVED RATE (a realized-vs-declared ratio, an answered rate) is meaningless past
 * one decimal, while a DECLARED share is a figure a human typed and must survive the round trip
 * unchanged.
 */
final class PercentDisplay
{
    /** A derived rate. One decimal is the last one that means anything on a ratio. */
    public const DECIMALS_RATE = 1;

    /** A figure a human typed. Two decimals, because that is what the share columns store. */
    public const DECIMALS_DECLARED = 2;

    /**
     * The bare number, no glyph — `"30"`, `"12.5"`.
     *
     * Null in, null out, for MoneyFormat's reason: "not computable" is a real answer that the caller
     * words for itself, and printing 0% for it states the opposite of the truth.
     */
    public static function value(int|float|null $percent, int $decimals = self::DECIMALS_RATE): ?string
    {
        if ($percent === null) {
            return null;
        }

        $decimals = max(0, $decimals);
        $formatted = number_format((float) $percent, $decimals, '.', '');

        return $decimals === 0 ? $formatted : rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * The same number with the glyph — `"30%"`. What a screen prints.
     */
    public static function display(int|float|null $percent, int $decimals = self::DECIMALS_RATE): ?string
    {
        $value = self::value($percent, $decimals);

        return $value === null ? null : $value.'%';
    }

    /**
     * A rate from its two halves, so no caller writes the division — or the zero-denominator branch.
     *
     * A rate over nothing is NULL, never 0: "0% of the challenges were answered" and "no challenge
     * has been published" are different sentences, and only one of them is about the market.
     */
    public static function rate(int $numerator, int $denominator, int $decimals = self::DECIMALS_RATE): ?string
    {
        if ($denominator <= 0) {
            return null;
        }

        return self::value($numerator / $denominator * 100, $decimals);
    }
}
