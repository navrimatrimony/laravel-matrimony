<?php

namespace App\Support;

/**
 * Maps owner strictness to minimum match score (0–100) using the same scale as {@see \App\Services\Matching\MatchingService}.
 */
final class ContactVisibilityStrictness
{
    public const RELAXED = 'relaxed';

    public const BALANCED = 'balanced';

    public const STRICT = 'strict';

    public static function minMatchScore(string $strictness): int
    {
        $s = strtolower(trim($strictness));

        return match ($s) {
            self::RELAXED => 40,
            self::STRICT => 70,
            default => 55,
        };
    }
}
