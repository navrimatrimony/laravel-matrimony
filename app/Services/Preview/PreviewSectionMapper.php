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
        'relatives',
        'siblings',
        'property_assets',
        // Phase-5: horoscope is array-of-rows (for shared engine), not scalar.
        'horoscope',
        // Phase-5: legal_cases is list of rows (type, court, notes, etc.).
        'legal',
    ];

    private const SECTION_LABELS = [
        'core' => 'Core Details',
        'contacts' => 'Contacts',
        'children' => 'Children',
        'siblings' => 'Siblings',
        'education' => 'Education',
        'career' => 'Career',
        'addresses' => 'Addresses',
        'relatives' => 'Relatives & Family Network',
        'property_summary' => 'Property Summary',
        'property_assets' => 'Property Assets',
        'horoscope' => 'Horoscope',
        'legal' => 'Legal cases',
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
        'relatives' => 'relatives',
        'siblings' => 'siblings',
        'property_summary' => 'property_summary',
        'property_assets' => 'property_assets',
        'horoscope' => 'horoscope',
        'legal' => 'legal_cases',
        'preferences' => 'preferences',
        'narrative' => 'extended_narrative',
    ];

    /** Section keys that store a single value (string/null) rather than array. */
    private const SCALAR_SECTIONS = ['property_summary', 'narrative'];

    public function map(array $parsedJson): array
    {
        $out = [];
        foreach (self::SOURCE_KEYS as $sectionKey => $sourceKey) {
            $data = $parsedJson[$sourceKey] ?? null;
            if (in_array($sectionKey, self::SCALAR_SECTIONS, true)) {
                if (! (is_scalar($data) || $data === null)) {
                    // Best-effort: if AI/parser returned an array, take first non-empty scalar value; otherwise null.
                    $firstScalar = null;
                    if (is_array($data)) {
                        foreach ($data as $value) {
                            if (is_scalar($value) && (string) $value !== '') {
                                $firstScalar = $value;
                                break;
                            }
                        }
                    }
                    $data = $firstScalar;
                }
            } elseif (!is_array($data)) {
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
