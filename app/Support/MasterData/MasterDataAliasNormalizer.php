<?php

namespace App\Support\MasterData;

use App\Services\Ocr\OcrNormalize;

/**
 * Normalization for master alias rows (must match {@see \App\Services\MasterData\MasterDataTranslationImportService}).
 * Also provides a second pass compatible with {@see \App\Services\MasterData\ReligionCasteSubCasteResolver} lookups.
 */
final class MasterDataAliasNormalizer
{
    /**
     * Canonical form stored in `*_aliases.normalized_alias` by import/sync.
     */
    public static function normalizeForStoredAlias(string $s): string
    {
        $t = OcrNormalize::normalizeDigits($s);
        $t = self::normalizeKnownMarathiVariants($t);
        $t = mb_strtolower(trim($t));
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    /**
     * Alternate normalization (punctuation → space) for legacy / OCR-heavy raw text.
     *
     * @return string[]
     */
    public static function normalizedLookupCandidates(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $a = self::normalizeForStoredAlias($raw);
        $b = self::normalizeResolverStyle($raw);
        $variant = self::normalizeForStoredAlias(self::normalizeKnownMarathiVariants($raw));

        return array_values(array_unique(array_filter([$a, $b, $variant], fn ($v) => $v !== '')));
    }

    private static function normalizeResolverStyle(string $s): string
    {
        $t = OcrNormalize::normalizeDigits($s);
        $t = self::normalizeKnownMarathiVariants($t);
        $t = mb_strtolower(trim($t));
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    private static function normalizeKnownMarathiVariants(string $value): string
    {
        return str_replace(
            ['कुली', 'कुळी मराठा', 'कुली मराठा'],
            ['कुळी', 'कुळी मराठा', 'कुळी मराठा'],
            $value
        );
    }
}
