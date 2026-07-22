<?php

namespace App\Support;

/**
 * Fuzzy person-name matching for duplicate detection (PO decision 2026-07-22).
 *
 * Why this exists as NEW code (one-engine rule was checked first):
 * - DuplicateDetectionService compares an intake SNAPSHOT with exact string
 *   equality and returns a single verdict — no fuzzy names, no scoring.
 * - IntakeDuplicateFieldMatchEvaluator compares two BiodataIntake models and
 *   its normalizeName() only strips punctuation — "Shriram" vs "Sriram" still
 *   fails there.
 * Neither can answer "does this typed name plausibly equal that stored name",
 * which the interactive Suchak duplicate-check needs. The normalization
 * semantics below are a superset of the evaluator's, so the intake side can
 * adopt this class later instead of keeping its own copy.
 *
 * Folding targets Marathi names written in Latin script, where the same name
 * has many common spellings: Shriram/Sriram/Shreeram, Dnyaneshwar/Gyaneshwar,
 * Jayram/Jairam, Kadam/Kadamm. Devanagari-vs-Latin cross-script matching is
 * NOT attempted here (stored names in this system are Latin); if that need
 * appears, add a devanagari→latin fold in foldToken, not a second matcher.
 */
final class NameMatcher
{
    public const LEVEL_EXACT = 'exact';

    public const LEVEL_STRONG = 'strong';

    public const LEVEL_PARTIAL = 'partial';

    public const LEVEL_NONE = 'none';

    /** Lowercase, letters-only, single-spaced. */
    public static function normalize(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $value = mb_strtolower(trim($name));
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Phonetic-ish fold of one Latin token so common Marathi spelling variants
     * collapse to the same key. Order matters: digraphs first, then vowel
     * folds, then repeat-squeeze, then the optional trailing 'a'.
     */
    public static function foldToken(string $token): string
    {
        $t = $token;
        // Aspirated/compound consonants → base consonant.
        $t = str_replace(['chh', 'sh', 'kh', 'gh', 'jh', 'th', 'dh', 'ph', 'bh'], ['c', 's', 'k', 'g', 'j', 't', 'd', 'f', 'b'], $t);
        $t = str_replace('ch', 'c', $t);
        // Long/alternate vowels and glides.
        $t = str_replace(['ee', 'oo', 'aa'], ['i', 'u', 'a'], $t);
        $t = strtr($t, ['w' => 'v', 'y' => 'i', 'z' => 'j']);
        // Squeeze repeated letters (Kadamm → Kadam).
        $t = preg_replace('/(.)\1+/', '$1', $t) ?? $t;
        // Optional trailing 'a' (Rama → Ram) — keep short names intact.
        if (strlen($t) > 3 && str_ends_with($t, 'a')) {
            $t = substr($t, 0, -1);
        }

        return $t;
    }

    /** @return array<int, string> */
    public static function foldedTokens(?string $name): array
    {
        $normalized = self::normalize($name);
        if ($normalized === null) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $token): string => self::foldToken($token),
            explode(' ', $normalized),
        )));
    }

    /**
     * exact  — normalized strings equal.
     * strong — folded token SETS equal (order-independent), or every token of
     *          the shorter name matches one in the longer (fold-equal or edit
     *          distance ≤1 for tokens ≥4 chars) with ≥2 tokens compared.
     * partial — at least one folded token (≥4 chars) matches.
     * none   — nothing matches.
     */
    public static function matchLevel(?string $a, ?string $b): string
    {
        $normA = self::normalize($a);
        $normB = self::normalize($b);
        if ($normA === null || $normB === null) {
            return self::LEVEL_NONE;
        }
        if ($normA === $normB) {
            return self::LEVEL_EXACT;
        }

        $tokensA = self::foldedTokens($a);
        $tokensB = self::foldedTokens($b);
        if ($tokensA === [] || $tokensB === []) {
            return self::LEVEL_NONE;
        }

        $setA = $tokensA;
        $setB = $tokensB;
        sort($setA);
        sort($setB);
        if ($setA === $setB) {
            return self::LEVEL_STRONG;
        }

        [$shorter, $longer] = count($tokensA) <= count($tokensB) ? [$tokensA, $tokensB] : [$tokensB, $tokensA];
        $matched = 0;
        foreach ($shorter as $token) {
            foreach ($longer as $candidate) {
                if (self::tokensMatch($token, $candidate)) {
                    $matched++;

                    continue 2;
                }
            }
        }

        if ($matched === count($shorter) && count($shorter) >= 2) {
            return self::LEVEL_STRONG;
        }

        foreach ($shorter as $token) {
            if (strlen($token) < 4) {
                continue;
            }
            foreach ($longer as $candidate) {
                if (self::tokensMatch($token, $candidate)) {
                    return self::LEVEL_PARTIAL;
                }
            }
        }

        return self::LEVEL_NONE;
    }

    private static function tokensMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (strlen($a) >= 4 && strlen($b) >= 4) {
            return levenshtein($a, $b) <= 1;
        }

        return false;
    }
}
