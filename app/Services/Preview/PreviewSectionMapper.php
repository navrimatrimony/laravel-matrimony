<?php

namespace App\Services\Preview;

/**
 * Phase-5 Day-14: Single authority for section typing.
 * Returns structured metadata (type, label, data) per section. No rendering.
 */
class PreviewSectionMapper
{
    private const LIST_SECTIONS = [
        'contacts',
        'children',
        'education',
        'career',
        'addresses',
        'property_assets',
        'legal_cases',
    ];

    private const SECTION_LABELS = [
        'core' => 'Core Details',
        'contacts' => 'Contacts',
        'children' => 'Children',
        'education' => 'Education',
        'career' => 'Career',
        'addresses' => 'Addresses',
        'property_summary' => 'Property Summary',
        'property_assets' => 'Property Assets',
        'horoscope' => 'Horoscope',
        'legal_cases' => 'Legal Cases',
        'preferences' => 'Preferences',
        'narrative' => 'Narrative',
    ];

    private const SOURCE_KEYS = [
        'core' => 'core',
        'contacts' => 'contacts',
        'children' => 'children',
        'education' => 'education_history',
        'career' => 'career_history',
        'addresses' => 'addresses',
        'property_summary' => 'property_summary',
        'property_assets' => 'property_assets',
        'horoscope' => 'horoscope',
        'legal_cases' => 'legal_cases',
        'preferences' => 'preferences',
        'narrative' => 'extended_narrative',
    ];

    public function map(array $parsedJson): array
    {
        $out = [];
        foreach (self::SOURCE_KEYS as $sectionKey => $sourceKey) {
            $data = $parsedJson[$sourceKey] ?? [];
            if (!is_array($data)) {
                $data = [];
            }
            $out[$sectionKey] = [
                'type' => in_array($sectionKey, self::LIST_SECTIONS, true) ? 'list' : 'object',
                'label' => self::SECTION_LABELS[$sectionKey],
                'data' => $data,
            ];
        }
        return $out;
    }
}
