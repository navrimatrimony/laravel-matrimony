<?php

namespace App\Support;

/**
 * Pick display label from master row: locale-aware with MR-first fallback for Marathi UI.
 */
final class BilingualMasterLabel
{
    public static function preferred(?string $labelMr, ?string $labelEn = null, ?string $labelLegacy = null): string
    {
        $mr = trim((string) ($labelMr ?? ''));
        $en = trim((string) ($labelEn ?? ''));
        if ($en === '') {
            $en = trim((string) ($labelLegacy ?? ''));
        }

        if (app()->getLocale() === 'en') {
            return $en !== '' ? $en : $mr;
        }

        return $mr !== '' ? $mr : $en;
    }
}
