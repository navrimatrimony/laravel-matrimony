<?php

namespace App\Support\Suchak;

use Illuminate\Support\Str;

/**
 * Humanises a Suchak enum/key into a display label.
 *
 * Column localisation is not this class's job — {@see \App\Support\LocalizedText::column()}
 * owns the "Marathi column with English fallback" rule for every table, Suchak's included.
 */
final class SuchakLocalizedText
{
    public static function label(?string $value, string $group = 'common'): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }

        return self::labelOrNull($value, $group)
            ?? Str::of($value)->replace('_', ' ')->title()->toString();
    }

    /**
     * Same lookup as {@see label()} but WITHOUT the titleised-English fallback:
     * null means "this enum value has no translation yet".
     *
     * Screens that must never leak a raw English enum into a Marathi UI use this
     * and substitute their own neutral wording (see
     * `suchak.labels.unknown`). Blade surfaces that are happy with a titleised
     * token keep calling label() — one vocabulary, two fallback policies.
     */
    public static function labelOrNull(?string $value, string $group = 'common'): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (["suchak.labels.{$group}.{$value}", "suchak.labels.common.{$value}"] as $key) {
            $translated = __($key);
            if ($translated !== $key && is_string($translated)) {
                return $translated;
            }
        }

        return null;
    }
}
