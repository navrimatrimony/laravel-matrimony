<?php

namespace App\Services\ProfileForm;

final class ProfileFormSectionSchema
{
    /**
     * Shared editable sections currently rendered by the canonical full form.
     *
     * Photo remains a wizard/profile section, but is handled by the dedicated
     * photo upload flow instead of the shared full editable form.
     *
     * @var list<string>
     */
    private const FULL_FORM_SECTION_KEYS = [
        'basic-info',
        'physical',
        'education-career',
        'family-details',
        'siblings',
        'relatives',
        'alliance',
        'property',
        'horoscope',
        'about-me',
        'about-preferences',
    ];

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     partial: string|null,
     *     editable: bool,
     *     surface: 'shared'|'wizard-only'|'intake-only',
     *     display_order: int,
     *     in_full_form: bool
     * }>
     */
    public static function sections(): array
    {
        $order = config('field_catalog.section_order', []);
        $labels = config('field_catalog.section_labels', []);
        if (! is_array($order)) {
            $order = [];
        }
        if (! is_array($labels)) {
            $labels = [];
        }

        $sections = [];
        foreach (array_values($order) as $index => $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $inFullForm = in_array($key, self::FULL_FORM_SECTION_KEYS, true);
            $sections[] = [
                'key' => $key,
                'label' => (string) ($labels[$key] ?? self::fallbackLabel($key)),
                'partial' => $inFullForm ? self::partialPathFor($key) : null,
                'editable' => $inFullForm,
                'surface' => $key === 'photo' ? 'wizard-only' : 'shared',
                'display_order' => $index + 1,
                'in_full_form' => $inFullForm,
            ];
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    public static function orderedKeys(): array
    {
        return array_map(
            static fn (array $section): string => $section['key'],
            self::sections()
        );
    }

    /**
     * @return list<string>
     */
    public static function fullFormSectionKeys(): array
    {
        return self::FULL_FORM_SECTION_KEYS;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     partial: string|null,
     *     editable: bool,
     *     surface: 'shared'|'wizard-only'|'intake-only',
     *     display_order: int,
     *     in_full_form: bool
     * }>
     */
    public static function fullFormSections(): array
    {
        return array_values(array_filter(
            self::sections(),
            static fn (array $section): bool => $section['in_full_form'] === true
        ));
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     partial: string|null,
     *     editable: bool,
     *     surface: 'shared'|'wizard-only'|'intake-only',
     *     display_order: int,
     *     in_full_form: bool
     * }|null
     */
    public static function forKey(string $key): ?array
    {
        foreach (self::sections() as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    private static function partialPathFor(string $key): string
    {
        return 'matrimony.profile.wizard.sections.'.str_replace('-', '_', $key);
    }

    private static function fallbackLabel(string $key): string
    {
        return 'wizard.'.str_replace('-', '_', $key);
    }
}
