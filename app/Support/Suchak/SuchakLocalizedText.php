<?php

namespace App\Support\Suchak;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SuchakLocalizedText
{
    public static function column(Model|array|null $source, string $baseColumn, ?string $locale = null): string
    {
        if ($source === null) {
            return '';
        }

        $locale ??= app()->getLocale();
        $baseValue = self::rawValue($source, $baseColumn);

        if ($locale === 'mr') {
            $marathiValue = self::rawValue($source, $baseColumn.'_mr');

            if ($marathiValue !== '') {
                return $marathiValue;
            }
        }

        return $baseValue;
    }

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

    private static function rawValue(Model|array $source, string $column): string
    {
        $value = $source instanceof Model
            ? ($source->getAttribute($column) ?? '')
            : ($source[$column] ?? '');

        return trim((string) $value);
    }
}
