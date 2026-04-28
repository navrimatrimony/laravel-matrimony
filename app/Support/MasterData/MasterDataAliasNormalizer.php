<?php

namespace App\Support\MasterData;

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
        $t = mb_strtolower(trim($s));
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

        return array_values(array_unique(array_filter([$a, $b], fn ($v) => $v !== '')));
    }

    private static function normalizeResolverStyle(string $s): string
    {
        $t = mb_strtolower(trim($s));
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }
}
