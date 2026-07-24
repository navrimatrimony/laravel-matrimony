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

        foreach (["suchak.labels.{$group}.{$value}", "suchak.labels.common.{$value}"] as $key) {
            $translated = __($key);
            if ($translated !== $key) {
                return (string) $translated;
            }
        }

        return Str::of($value)->replace('_', ' ')->title()->toString();
    }
}
