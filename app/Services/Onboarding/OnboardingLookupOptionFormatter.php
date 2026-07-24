<?php

namespace App\Services\Onboarding;

use App\Support\LocalizedText;

class OnboardingLookupOptionFormatter
{
    /**
     * @param  array<int, string>  $localeColumns
     * @param  array<int, string>  $englishColumns
     * @return array{label: string, translation_missing: bool}
     */
    public function label(object|array $row, string $locale, array $localeColumns, array $englishColumns): array
    {
        $isMarathi = LocalizedText::isMarathi($locale);

        if ($isMarathi) {
            foreach ($localeColumns as $column) {
                $localized = LocalizedText::value($row, $column);
                if ($localized !== null) {
                    return [
                        'label' => $localized,
                        'translation_missing' => false,
                    ];
                }
            }
        }

        foreach ($englishColumns as $column) {
            $english = LocalizedText::value($row, $column);
            if ($english !== null) {
                return [
                    'label' => $english,
                    'translation_missing' => $isMarathi,
                ];
            }
        }

        $fallbackColumns = array_values(array_unique(array_merge($localeColumns, $englishColumns)));
        foreach ($fallbackColumns as $column) {
            $fallback = LocalizedText::value($row, $column);
            if ($fallback !== null) {
                return [
                    'label' => $fallback,
                    'translation_missing' => $isMarathi,
                ];
            }
        }

        return [
            'label' => 'Option',
            'translation_missing' => $isMarathi,
        ];
    }
}
