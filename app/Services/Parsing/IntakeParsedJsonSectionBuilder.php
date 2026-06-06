<?php

namespace App\Services\Parsing;

final class IntakeParsedJsonSectionBuilder
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.75;

    public function __construct(
        private IntakeParsedSnapshotSkeleton $skeleton,
    ) {}

    /**
     * @param  array<string,mixed>  $parsed
     * @param  array<string,mixed>|null  $normalizedDraft
     * @return array{section_order:list<string>,sectioned:array<string,mixed>,missing_map:array<string,array<string,mixed>>}
     */
    public function build(array $parsed, ?array $normalizedDraft = null): array
    {
        $source = $parsed;
        $parsed = $this->skeleton->ensure($parsed);
        $core = $parsed['core'];

        $sectioned = $this->skeleton->sectionedDefaults();
        $sectioned['review_needed'] = [];
        $sectioned['basic-info'] = $this->fieldObjects($parsed, $normalizedDraft, 'basic-info', 'core', $core, [
            'full_name',
            'gender',
            'date_of_birth',
            'birth_time',
            'birth_place_text',
            'religion',
            'religion_id',
            'caste',
            'caste_id',
            'sub_caste',
            'sub_caste_id',
            'marital_status',
            'marital_status_id',
            'address_line',
            'primary_contact_number',
        ]);
        $sectioned['physical'] = $this->fieldObjects($parsed, $normalizedDraft, 'physical', 'core', $core, [
            'height_cm',
            'weight_kg',
            'complexion',
            'complexion_id',
            'blood_group',
            'blood_group_id',
            'physical_build',
            'physical_build_id',
            'spectacles_lens',
            'physical_condition',
            'diet',
            'diet_id',
            'smoking',
            'smoking_status',
            'drinking',
            'drinking_status',
        ]);
        $sectioned['education-career'] = $this->fieldObjects($parsed, $normalizedDraft, 'education-career', 'core', $core, [
            'highest_education',
            'highest_education_other',
            'specialization',
            'occupation_title',
            'company_name',
            'annual_income',
            'income_currency',
            'work_location_text',
            'work_city_id',
            'work_state_id',
        ]);
        $sectioned['family-details'] = $this->fieldObjects($parsed, $normalizedDraft, 'family-details', 'core', $core, [
            'father_name',
            'father_occupation',
            'father_extra_info',
            'father_contact_1',
            'father_contact_2',
            'father_contact_3',
            'mother_name',
            'mother_occupation',
            'mother_extra_info',
            'mother_contact_1',
            'mother_contact_2',
            'mother_contact_3',
            'brothers_count',
            'sisters_count',
            'family_income',
            'family_type',
            'family_type_id',
        ], [
            'brothers_count' => $this->derivedFromLegacyAlias($source, 'brothers_count', 'brother_count'),
            'sisters_count' => $this->derivedFromLegacyAlias($source, 'sisters_count', 'sister_count'),
        ]);
        $sectioned['family-details']['parents_addresses'] = $parsed['parents_addresses'];
        $sectioned['siblings'] = $parsed['siblings'];
        $sectioned['relatives'] = $parsed['relatives'];
        $sectioned['alliance'] = $parsed['alliance_networks'];

        $propertySummary = is_array($parsed['property_summary']) ? $parsed['property_summary'] : [];
        $propertyExplicit = is_array($source['property_summary'] ?? null)
            && $this->hasMeaningfulValue($source['property_summary'])
                ? $source['property_summary']
                : [];
        $sectioned['property'] = [
            'summary' => $this->fieldObjects(
                $parsed,
                $normalizedDraft,
                'property',
                'property_summary',
                $propertySummary,
                array_keys($this->skeleton->propertySummaryDefaults()),
                [],
                $propertyExplicit
            ),
            'assets' => $parsed['property_assets'],
        ];

        $horoscope = is_array($parsed['horoscope'][0] ?? null) ? $parsed['horoscope'][0] : [];
        $horoscopeExplicit = is_array($source['horoscope'][0] ?? null) ? $source['horoscope'][0] : [];
        $sectioned['horoscope'] = [
            'basic_religious_details' => $this->fieldObjects(
                $parsed,
                $normalizedDraft,
                'horoscope',
                'horoscope',
                $horoscope,
                [
                    'mangal_dosh_type',
                    'mangal_dosh_type_id',
                    'navras_name',
                    'devak',
                    'kuldaivat',
                    'gotra',
                    'birth_weekday',
                ],
                [],
                $horoscopeExplicit
            ),
            'horoscope_details' => $this->fieldObjects(
                $parsed,
                $normalizedDraft,
                'horoscope',
                'horoscope',
                $horoscope,
                [
                    'nakshatra',
                    'nakshatra_id',
                    'charan',
                    'rashi',
                    'rashi_id',
                    'gan',
                    'gan_id',
                    'nadi',
                    'nadi_id',
                    'yoni',
                    'yoni_id',
                    'varna',
                    'vashya',
                    'rashi_lord',
                ],
                [],
                $horoscopeExplicit
            ),
        ];
        $sectioned['legal-cases'] = $parsed['legal_cases'];

        $narrative = $parsed['extended_narrative'];
        $sectioned['about-me'] = $this->fieldObjects($parsed, $normalizedDraft, 'about-me', 'extended_narrative', $narrative, [
            'narrative_about_me',
            'additional_notes',
        ]);
        $sectioned['about-preferences'] = $this->fieldObjects(
            $parsed,
            $normalizedDraft,
            'about-preferences',
            'extended_narrative',
            $narrative,
            ['narrative_expectations']
        );
        $sectioned['photo'] = [];

        return [
            'section_order' => $this->skeleton->sectionOrder(),
            'sectioned' => $sectioned,
            'missing_map' => $this->buildMissingMap($sectioned),
        ];
    }

    /**
     * @param  array<string,mixed>  $parsed
     * @param  array<string,mixed>|null  $normalizedDraft
     * @param  array<string,mixed>  $values
     * @param  list<string>  $fields
     * @param  array<string,bool>  $derivedFields
     * @param  array<string,mixed>|null  $explicitValues
     * @return array<string,array<string,mixed>>
     */
    private function fieldObjects(
        array $parsed,
        ?array $normalizedDraft,
        string $section,
        string $sourceSection,
        array $values,
        array $fields,
        array $derivedFields = [],
        ?array $explicitValues = null,
    ): array {
        $out = [];
        $explicitValues ??= $values;
        foreach ($fields as $field) {
            $sourceKey = $sourceSection.'.'.$field;
            $value = $values[$field] ?? null;
            $explicit = array_key_exists($field, $explicitValues);
            $out[$field] = $this->fieldObject(
                $parsed,
                $normalizedDraft,
                $section,
                $sourceSection,
                $sourceKey,
                $field,
                $value,
                $explicit,
                $derivedFields[$field] ?? false
            );
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $parsed
     * @param  array<string,mixed>|null  $normalizedDraft
     * @return array{value:mixed,raw:?string,source_key:string,source_section:string,confidence:float,status:string,missing_reason:?string}
     */
    private function fieldObject(
        array $parsed,
        ?array $normalizedDraft,
        string $section,
        string $sourceSection,
        string $sourceKey,
        string $field,
        mixed $value,
        bool $explicit,
        bool $derived,
    ): array {
        $flagReason = $this->reviewFlagReason($normalizedDraft, $sourceKey, $field);
        $rejectedReason = $this->rejectedReason($flagReason);
        $hasValue = ! $this->isEmptyValue($value);

        $confidence = $this->confidence($parsed, $sourceKey, $field, $hasValue, $explicit, $derived);
        $status = 'filled';
        $missingReason = null;

        if ($rejectedReason !== null) {
            $status = 'rejected';
            $missingReason = $rejectedReason;
            $value = null;
            $confidence = min($confidence, 0.15);
        } elseif (! $hasValue) {
            $status = $value === '' ? 'empty' : 'missing';
            $missingReason = $this->missingReason($normalizedDraft, $sourceSection, $field, $flagReason);
            $confidence = 0.0;
        } elseif ($derived) {
            $status = 'derived';
        } elseif ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
            $status = 'low_confidence';
            $missingReason = 'low_confidence';
        }

        return [
            'value' => $value,
            'raw' => $this->rawValue($normalizedDraft, $sourceSection, $field),
            'source_key' => $sourceKey,
            'source_section' => $sourceSection,
            'confidence' => $confidence,
            'status' => $status,
            'missing_reason' => $missingReason,
        ];
    }

    /**
     * @param  array<string,mixed>  $parsed
     */
    private function confidence(
        array $parsed,
        string $sourceKey,
        string $field,
        bool $hasValue,
        bool $explicit,
        bool $derived,
    ): float {
        $map = is_array($parsed['confidence_map'] ?? null) ? $parsed['confidence_map'] : [];
        if (array_key_exists($sourceKey, $map) && is_numeric($map[$sourceKey])) {
            return max(0.0, min(1.0, (float) $map[$sourceKey]));
        }
        if (array_key_exists($field, $map) && is_numeric($map[$field])) {
            return max(0.0, min(1.0, (float) $map[$field]));
        }
        if (! $hasValue) {
            return 0.0;
        }

        return ($derived || ! $explicit) ? 0.65 : 0.85;
    }

    /**
     * @param  array<string,mixed>|null  $normalizedDraft
     */
    private function rawValue(?array $normalizedDraft, string $sourceSection, string $field): ?string
    {
        if ($normalizedDraft === null) {
            return null;
        }

        $value = match ($sourceSection) {
            'core' => $normalizedDraft['normalized']['core'][$field] ?? null,
            'property_summary' => $normalizedDraft['normalized']['property_summary'][$field] ?? null,
            'horoscope' => $normalizedDraft['normalized']['horoscope'][$field] ?? null,
            'extended_narrative' => $normalizedDraft['normalized']['extended_narrative'][$field]
                ?? $normalizedDraft['normalized']['preferences'][$field]
                ?? null,
            default => null,
        };

        if (! is_scalar($value)) {
            return null;
        }
        $raw = trim((string) $value);

        return $raw !== '' ? $raw : null;
    }

    /**
     * @param  array<string,mixed>|null  $normalizedDraft
     */
    private function reviewFlagReason(?array $normalizedDraft, string $sourceKey, string $field): ?string
    {
        if ($normalizedDraft === null) {
            return null;
        }
        foreach ($normalizedDraft['review_flags'] ?? [] as $flag) {
            if (! is_array($flag)) {
                continue;
            }
            $flagField = (string) ($flag['field'] ?? '');
            if ($flagField === $sourceKey || $flagField === $field) {
                $reason = trim((string) ($flag['reason'] ?? ''));

                return $reason !== '' ? $reason : null;
            }
        }

        return null;
    }

    private function rejectedReason(?string $flagReason): ?string
    {
        if ($flagReason === null) {
            return null;
        }
        if (str_contains($flagReason, 'contaminat') || str_contains($flagReason, 'mixed_field')) {
            return 'rejected_as_contaminated';
        }
        if (str_contains($flagReason, 'noise') || str_contains($flagReason, 'heading')) {
            return 'rejected_as_noise';
        }
        if (str_contains($flagReason, 'invalid')) {
            return 'invalid_format';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $normalizedDraft
     */
    private function missingReason(
        ?array $normalizedDraft,
        string $sourceSection,
        string $field,
        ?string $flagReason,
    ): string {
        if ($flagReason !== null && (
            str_contains($flagReason, 'missing')
            || str_contains($flagReason, 'omitted')
            || str_contains($flagReason, 'unmapped')
        )) {
            return 'parser_omitted';
        }
        if ($normalizedDraft === null) {
            return 'not_present_in_biodata';
        }

        $container = match ($sourceSection) {
            'core' => $normalizedDraft['normalized']['core'] ?? null,
            'property_summary' => $normalizedDraft['normalized']['property_summary'] ?? null,
            'horoscope' => $normalizedDraft['normalized']['horoscope'] ?? null,
            'extended_narrative' => $normalizedDraft['normalized']['extended_narrative']
                ?? $normalizedDraft['normalized']['preferences']
                ?? null,
            default => null,
        };
        if (is_array($container) && ! array_key_exists($field, $container)) {
            return 'not_found_in_normalized_draft';
        }

        return 'not_present_in_biodata';
    }

    /**
     * @param  array<string,mixed>  $sectioned
     * @return array<string,array<string,mixed>>
     */
    private function buildMissingMap(array $sectioned): array
    {
        $missing = [];
        foreach ($sectioned as $section => $contents) {
            $this->collectMissingFields((string) $section, (string) $section, $contents, $missing);
        }

        return $missing;
    }

    /**
     * @param  array<string,array<string,mixed>>  $missing
     */
    private function collectMissingFields(string $section, string $path, mixed $value, array &$missing): void
    {
        if (! is_array($value)) {
            return;
        }
        if ($this->isFieldObject($value)) {
            if (($value['status'] ?? 'filled') !== 'filled' && ($value['status'] ?? null) !== 'derived') {
                $field = (string) array_slice(explode('.', $path), -1)[0];
                $missing[$path] = [
                    'section' => $section,
                    'field' => $field,
                    'status' => $value['status'],
                    'reason' => $value['missing_reason'],
                    'confidence' => (float) $value['confidence'],
                ];
            }

            return;
        }
        foreach ($value as $key => $child) {
            if (is_int($key)) {
                continue;
            }
            $this->collectMissingFields($section, $path.'.'.$key, $child, $missing);
        }
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function isFieldObject(array $value): bool
    {
        return array_keys($value) === [
            'value',
            'raw',
            'source_key',
            'source_section',
            'confidence',
            'status',
            'missing_reason',
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function derivedFromLegacyAlias(array $source, string $canonical, string $legacy): bool
    {
        $core = is_array($source['core'] ?? null) ? $source['core'] : [];

        return $this->isEmptyValue($core[$canonical] ?? null) && ! $this->isEmptyValue($core[$legacy] ?? null);
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * @param  array<string,mixed>  $values
     */
    private function hasMeaningfulValue(array $values): bool
    {
        foreach ($values as $value) {
            if ($value === true || (! is_bool($value) && ! $this->isEmptyValue($value))) {
                return true;
            }
        }

        return false;
    }
}
