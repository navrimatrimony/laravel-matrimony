<?php

namespace App\Services\Onboarding;

use Illuminate\Database\Eloquent\Model;

class OnboardingLookupOptionFormatter
{
    /**
     * @param  array<int, string>  $localeColumns
     * @param  array<int, string>  $englishColumns
     * @return array{label: string, translation_missing: bool}
     */
    public function label(object|array $row, string $locale, array $localeColumns, array $englishColumns): array
    {
        $localized = $locale === 'mr' ? $this->firstFilled($row, $localeColumns) : null;
        if ($localized !== null) {
            return [
                'label' => $localized,
                'translation_missing' => false,
            ];
        }

        $english = $this->firstFilled($row, $englishColumns);
        if ($english !== null) {
            return [
                'label' => $english,
                'translation_missing' => $locale === 'mr',
            ];
        }

        $fallback = $this->firstFilled($row, array_values(array_unique(array_merge($localeColumns, $englishColumns))));

        return [
            'label' => $fallback ?? 'Option',
            'translation_missing' => $locale === 'mr',
        ];
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function firstFilled(object|array $row, array $columns): ?string
    {
        foreach ($columns as $column) {
            $value = $this->value($row, $column);
            if ($value === null) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function value(object|array $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        if ($row instanceof Model) {
            return $row->getAttribute($key);
        }

        return $row->{$key} ?? null;
    }
}
