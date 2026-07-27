<?php

namespace App\Support;

/**
 * The ONE place Devanagari numerals are forced back to Latin 0-9.
 *
 * FROZEN workspace rule: every numeral a member ever sees — in either app's UI
 * and in any generated message — renders in Latin digits regardless of the
 * selected language. It was frozen on 2026-07-25 after Devanagari digits leaked
 * into the payment UI, and the leak was never hand-written copy: it came from a
 * locale-aware formatter (Carbon, NumberFormatter, an admin-entered string)
 * deciding for itself how to render a number in `mr`.
 *
 * So this exists as a guard at the exits, not as something callers must remember
 * everywhere: teaser strings on their way into an API payload, and push titles
 * and bodies on their way to FCM. Do not add a second copy of this table.
 */
final class LatinDigits
{
    /** @var array<string, string> */
    private const MAP = [
        '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
        '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
    ];

    public static function normalize(string $value): string
    {
        return strtr($value, self::MAP);
    }

    /**
     * Same, but null-safe — teaser fields are frequently nullable.
     */
    public static function normalizeNullable(?string $value): ?string
    {
        return $value === null ? null : self::normalize($value);
    }
}
