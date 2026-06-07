<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Services\Ocr\OcrNormalize;

/**
 * Compares normalized biodata draft preview values against intake parsed_json.
 */
final class IntakeNormalizedDraftParsedReconciler
{
    /** @var list<string> */
    private const CORE_FIELD_KEYS = [
        'full_name', 'gender', 'date_of_birth', 'birth_time', 'birth_place_text',
        'religion', 'caste', 'sub_caste', 'marital_status', 'address_line',
        'height_cm', 'complexion', 'blood_group', 'weight_kg', 'physical_build',
        'spectacles_lens', 'physical_condition', 'diet', 'smoking', 'drinking',
        'highest_education', 'occupation_title', 'company_name', 'annual_income',
        'work_location_text', 'specialization',
        'father_name', 'father_occupation', 'father_extra_info',
        'father_contact_1', 'father_contact_2', 'father_contact_3',
        'mother_name', 'mother_occupation', 'mother_extra_info',
        'mother_contact_1', 'mother_contact_2', 'mother_contact_3',
        'family_income', 'family_type', 'other_relatives_text',
        'primary_contact_number',
    ];

    /** @var list<string> */
    private const HOROSCOPE_FIELD_KEYS = [
        'mangal_dosh_type', 'navras_name', 'devak', 'kuldaivat', 'gotra', 'birth_weekday',
        'nakshatra', 'charan', 'rashi', 'gan', 'nadi', 'yoni', 'varna', 'vashya', 'rashi_lord',
    ];

    /** @var array<string, string> */
    private const CORE_FIELD_SECTIONS = [
        'full_name' => 'basic-info',
        'gender' => 'basic-info',
        'date_of_birth' => 'basic-info',
        'birth_time' => 'basic-info',
        'birth_place_text' => 'basic-info',
        'religion' => 'basic-info',
        'caste' => 'basic-info',
        'sub_caste' => 'basic-info',
        'marital_status' => 'basic-info',
        'address_line' => 'basic-info',
        'primary_contact_number' => 'basic-info',
        'height_cm' => 'physical',
        'complexion' => 'physical',
        'blood_group' => 'physical',
        'weight_kg' => 'physical',
        'physical_build' => 'physical',
        'spectacles_lens' => 'physical',
        'physical_condition' => 'physical',
        'diet' => 'physical',
        'smoking' => 'physical',
        'drinking' => 'physical',
        'highest_education' => 'education-career',
        'occupation_title' => 'education-career',
        'company_name' => 'education-career',
        'annual_income' => 'education-career',
        'work_location_text' => 'education-career',
        'specialization' => 'education-career',
        'father_name' => 'family-details',
        'father_occupation' => 'family-details',
        'father_extra_info' => 'family-details',
        'father_contact_1' => 'family-details',
        'father_contact_2' => 'family-details',
        'father_contact_3' => 'family-details',
        'mother_name' => 'family-details',
        'mother_occupation' => 'family-details',
        'mother_extra_info' => 'family-details',
        'mother_contact_1' => 'family-details',
        'mother_contact_2' => 'family-details',
        'mother_contact_3' => 'family-details',
        'family_income' => 'family-details',
        'family_type' => 'family-details',
        'other_relatives_text' => 'alliance',
    ];

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>|null  $parsedSnapshot
     * @return array{
     *     available: bool,
     *     draft_not_in_parsed: list<array{field: string, field_label: string, section_key: string, section_label: string, draft_value: string, parsed_value: ?string, kind: string}>,
     *     parsed_not_in_draft: list<array{field: string, field_label: string, section_key: string, section_label: string, draft_value: ?string, parsed_value: string, kind: string}>
     * }
     */
    public function reconcile(array $normalized, ?array $parsedSnapshot): array
    {
        $empty = [
            'available' => false,
            'draft_not_in_parsed' => [],
            'parsed_not_in_draft' => [],
        ];

        if (! is_array($parsedSnapshot) || $parsedSnapshot === []) {
            return $empty;
        }

        $draftNotInParsed = [];
        $parsedNotInDraft = [];

        $this->compareCoreFields($normalized, $parsedSnapshot, $draftNotInParsed, $parsedNotInDraft);
        $this->compareHoroscopeFields($normalized, $parsedSnapshot, $draftNotInParsed, $parsedNotInDraft);
        $this->compareRelationCollections(
            'siblings',
            'siblings',
            is_array($normalized['siblings'] ?? null) ? $normalized['siblings'] : [],
            is_array($parsedSnapshot['siblings'] ?? null) ? $parsedSnapshot['siblings'] : [],
            $draftNotInParsed,
            $parsedNotInDraft
        );
        $this->compareRelationCollections(
            'relatives',
            'relatives',
            is_array($normalized['relatives'] ?? null) ? $normalized['relatives'] : [],
            is_array($parsedSnapshot['relatives'] ?? null) ? $parsedSnapshot['relatives'] : [],
            $draftNotInParsed,
            $parsedNotInDraft
        );

        return [
            'available' => true,
            'draft_not_in_parsed' => $draftNotInParsed,
            'parsed_not_in_draft' => $parsedNotInDraft,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $parsedSnapshot
     * @param  list<array<string, mixed>>  $draftNotInParsed
     * @param  list<array<string, mixed>>  $parsedNotInDraft
     */
    private function compareCoreFields(
        array $normalized,
        array $parsedSnapshot,
        array &$draftNotInParsed,
        array &$parsedNotInDraft
    ): void {
        $draftCore = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $parsedCore = is_array($parsedSnapshot['core'] ?? null) ? $parsedSnapshot['core'] : [];

        foreach (self::CORE_FIELD_KEYS as $key) {
            $draftValue = $this->scalarValue($draftCore[$key] ?? null);
            $parsedValue = $this->scalarValue($parsedCore[$key] ?? null);
            if ($draftValue === '' && $parsedValue === '') {
                continue;
            }

            $field = 'core.'.$key;
            $fieldLabel = $this->fieldLabel($key);
            $sectionKey = self::CORE_FIELD_SECTIONS[$key] ?? 'basic-info';
            $sectionLabel = $this->sectionLabel($sectionKey);

            if ($draftValue !== '' && $parsedValue === '') {
                $draftNotInParsed[] = $this->row(
                    $field,
                    $fieldLabel,
                    $sectionKey,
                    $sectionLabel,
                    $draftValue,
                    null,
                    'missing_in_parsed'
                );

                continue;
            }

            if ($parsedValue !== '' && $draftValue === '') {
                $parsedNotInDraft[] = $this->row(
                    $field,
                    $fieldLabel,
                    $sectionKey,
                    $sectionLabel,
                    null,
                    $parsedValue,
                    'missing_in_draft'
                );

                continue;
            }

            if ($draftValue !== '' && $parsedValue !== '' && ! $this->valuesEquivalent($draftValue, $parsedValue)) {
                if ($key === 'other_relatives_text') {
                    $this->compareOtherRelativesSegments($draftValue, $parsedValue, $fieldLabel, $sectionKey, $sectionLabel, $draftNotInParsed, $parsedNotInDraft);

                    continue;
                }

                $draftNotInParsed[] = $this->row(
                    $field,
                    $fieldLabel,
                    $sectionKey,
                    $sectionLabel,
                    $draftValue,
                    $parsedValue,
                    'value_mismatch'
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $draftNotInParsed
     * @param  list<array<string, mixed>>  $parsedNotInDraft
     */
    private function compareOtherRelativesSegments(
        string $draftValue,
        string $parsedValue,
        string $fieldLabel,
        string $sectionKey,
        string $sectionLabel,
        array &$draftNotInParsed,
        array &$parsedNotInDraft
    ): void {
        $field = 'core.other_relatives_text';

        foreach ($this->splitRelativeSegments($draftValue) as $segment) {
            if ($segment === '' || $this->segmentPresentIn($segment, $parsedValue)) {
                continue;
            }
            $draftNotInParsed[] = $this->row(
                $field,
                $fieldLabel,
                $sectionKey,
                $sectionLabel,
                $segment,
                null,
                'missing_in_parsed'
            );
        }

        foreach ($this->splitRelativeSegments($parsedValue) as $segment) {
            if ($segment === '' || $this->segmentPresentIn($segment, $draftValue)) {
                continue;
            }
            $parsedNotInDraft[] = $this->row(
                $field,
                $fieldLabel,
                $sectionKey,
                $sectionLabel,
                null,
                $segment,
                'missing_in_draft'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $parsedSnapshot
     * @param  list<array<string, mixed>>  $draftNotInParsed
     * @param  list<array<string, mixed>>  $parsedNotInDraft
     */
    private function compareHoroscopeFields(
        array $normalized,
        array $parsedSnapshot,
        array &$draftNotInParsed,
        array &$parsedNotInDraft
    ): void {
        $draftHoroscope = is_array($normalized['horoscope'] ?? null) ? $normalized['horoscope'] : [];
        $parsedHoroscope = is_array($parsedSnapshot['horoscope'] ?? null) ? $parsedSnapshot['horoscope'] : [];
        $sectionKey = 'horoscope';
        $sectionLabel = $this->sectionLabel($sectionKey);

        foreach (self::HOROSCOPE_FIELD_KEYS as $key) {
            $draftValue = $this->scalarValue($draftHoroscope[$key] ?? null);
            $parsedValue = $this->scalarValue($parsedHoroscope[$key] ?? null);
            if ($draftValue === '' && $parsedValue === '') {
                continue;
            }

            $field = 'horoscope.'.$key;
            $fieldLabel = $this->fieldLabel($key);

            if ($draftValue !== '' && $parsedValue === '') {
                $draftNotInParsed[] = $this->row($field, $fieldLabel, $sectionKey, $sectionLabel, $draftValue, null, 'missing_in_parsed');

                continue;
            }

            if ($parsedValue !== '' && $draftValue === '') {
                $parsedNotInDraft[] = $this->row($field, $fieldLabel, $sectionKey, $sectionLabel, null, $parsedValue, 'missing_in_draft');

                continue;
            }

            if ($draftValue !== '' && $parsedValue !== '' && ! $this->valuesEquivalent($draftValue, $parsedValue)) {
                $draftNotInParsed[] = $this->row($field, $fieldLabel, $sectionKey, $sectionLabel, $draftValue, $parsedValue, 'value_mismatch');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $draftRows
     * @param  list<array<string, mixed>>  $parsedRows
     * @param  list<array<string, mixed>>  $draftNotInParsed
     * @param  list<array<string, mixed>>  $parsedNotInDraft
     */
    private function compareRelationCollections(
        string $collection,
        string $sectionKey,
        array $draftRows,
        array $parsedRows,
        array &$draftNotInParsed,
        array &$parsedNotInDraft
    ): void {
        $sectionLabel = $this->sectionLabel($sectionKey);
        $matchedParsed = [];

        foreach ($draftRows as $draftIndex => $draftRow) {
            if (! is_array($draftRow)) {
                continue;
            }

            $summary = $this->relationRowSummary($draftRow);
            if ($summary === '') {
                continue;
            }

            $relationType = $this->canonicalRelationType((string) ($draftRow['relation_type'] ?? ''));
            $matchIndex = $this->findMatchingRelationRowIndex($draftRow, $parsedRows, $matchedParsed);
            if ($matchIndex === null) {
                $field = $collection.'.'.$relationType.'.row_'.$draftIndex;
                $fieldLabel = $this->relationFieldLabel($collection, $relationType);
                $draftNotInParsed[] = $this->row(
                    $field,
                    $fieldLabel,
                    $sectionKey,
                    $sectionLabel,
                    $summary,
                    null,
                    'missing_in_parsed'
                );

                continue;
            }

            $matchedParsed[$matchIndex] = true;
            $parsedRow = is_array($parsedRows[$matchIndex] ?? null) ? $parsedRows[$matchIndex] : [];
            $parsedSummary = $this->relationRowSummary($parsedRow);
            if ($parsedSummary !== '' && ! $this->valuesEquivalent($summary, $parsedSummary)) {
                $field = $collection.'.'.$relationType.'.row_'.$draftIndex;
                $fieldLabel = $this->relationFieldLabel($collection, $relationType);
                $draftNotInParsed[] = $this->row(
                    $field,
                    $fieldLabel,
                    $sectionKey,
                    $sectionLabel,
                    $summary,
                    $parsedSummary,
                    'value_mismatch'
                );
            }
        }

        foreach ($parsedRows as $parsedIndex => $parsedRow) {
            if (! is_array($parsedRow) || isset($matchedParsed[$parsedIndex])) {
                continue;
            }

            $summary = $this->relationRowSummary($parsedRow);
            if ($summary === '') {
                continue;
            }

            $relationType = $this->canonicalRelationType((string) ($parsedRow['relation_type'] ?? ''));
            $field = $collection.'.'.$relationType.'.parsed_'.$parsedIndex;
            $fieldLabel = $this->relationFieldLabel($collection, $relationType);
            $parsedNotInDraft[] = $this->row(
                $field,
                $fieldLabel,
                $sectionKey,
                $sectionLabel,
                null,
                $summary,
                'missing_in_draft'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $needleRow
     * @param  list<array<string, mixed>>  $parsedRows
     * @param  array<int, bool>  $excludeIndexes
     */
    private function findMatchingRelationRowIndex(array $needleRow, array $parsedRows, array $excludeIndexes): ?int
    {
        $needleRelation = $this->canonicalRelationType((string) ($needleRow['relation_type'] ?? ''));
        $needleName = $this->normalizedCompareText((string) ($needleRow['name'] ?? ''));

        foreach ($parsedRows as $index => $parsedRow) {
            if (! is_array($parsedRow) || isset($excludeIndexes[$index])) {
                continue;
            }
            if ($this->canonicalRelationType((string) ($parsedRow['relation_type'] ?? '')) !== $needleRelation) {
                continue;
            }

            $parsedName = $this->normalizedCompareText((string) ($parsedRow['name'] ?? ''));
            if ($needleName !== '' && $parsedName !== '') {
                if ($needleName === $parsedName || str_contains($needleName, $parsedName) || str_contains($parsedName, $needleName)) {
                    return $index;
                }

                continue;
            }

            $needleSummary = $this->normalizedCompareText($this->relationRowSummary($needleRow));
            $parsedSummary = $this->normalizedCompareText($this->relationRowSummary($parsedRow));
            if ($needleSummary !== '' && $parsedSummary !== '' && ($needleSummary === $parsedSummary || str_contains($needleSummary, $parsedSummary) || str_contains($parsedSummary, $needleSummary))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function relationRowSummary(array $row): string
    {
        $parts = [];
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            $parts[] = $name;
        }
        foreach (['occupation', 'occupation_title', 'address_line', 'location_display', 'contact_number', 'notes'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(' · ', array_values(array_unique($parts)));
    }

    /**
     * @return array{field: string, field_label: string, section_key: string, section_label: string, draft_value: ?string, parsed_value: ?string, kind: string}
     */
    private function row(
        string $field,
        string $fieldLabel,
        string $sectionKey,
        string $sectionLabel,
        ?string $draftValue,
        ?string $parsedValue,
        string $kind
    ): array {
        return [
            'field' => $field,
            'field_label' => $fieldLabel,
            'section_key' => $sectionKey,
            'section_label' => $sectionLabel,
            'draft_value' => $draftValue,
            'parsed_value' => $parsedValue,
            'kind' => $kind,
        ];
    }

    /**
     * @return list<string>
     */
    private function splitRelativeSegments(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[;|]\s*/u', $value) ?: [];
        $segments = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t\n\r\0\x0B.;");
            if ($part !== '') {
                $segments[] = $part;
            }
        }

        return $segments !== [] ? $segments : [$value];
    }

    private function segmentPresentIn(string $segment, string $haystack): bool
    {
        $segmentNorm = $this->normalizedCompareText($segment);
        $haystackNorm = $this->normalizedCompareText($haystack);
        if ($segmentNorm === '' || $haystackNorm === '') {
            return false;
        }

        return str_contains($haystackNorm, $segmentNorm);
    }

    private function valuesEquivalent(string $left, string $right): bool
    {
        $leftNorm = $this->normalizedCompareText($left);
        $rightNorm = $this->normalizedCompareText($right);
        if ($leftNorm === '' || $rightNorm === '') {
            return false;
        }

        return $leftNorm === $rightNorm
            || str_contains($leftNorm, $rightNorm)
            || str_contains($rightNorm, $leftNorm);
    }

    private function scalarValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_int($value) || is_float($value)) {
            if ((float) $value === 0.0) {
                return '';
            }

            return trim((string) $value);
        }
        if (is_scalar($value)) {
            $text = trim((string) $value);
            if ($text === '' || in_array(mb_strtolower($text), ['0', '0.0', 'false', 'null', '—', '-', 'n/a', 'na'], true)) {
                return '';
            }

            return $text;
        }

        return '';
    }

    private function normalizedCompareText(string $value): string
    {
        return mb_strtolower(trim(OcrNormalize::normalizeDigits($value)));
    }

    private function canonicalRelationType(string $relationType): string
    {
        return mb_strtolower(trim(str_replace('-', '_', $relationType)));
    }

    private function fieldLabel(string $field): string
    {
        $translated = __('intake.core_suggestion_field.'.$field);
        if ($translated !== 'intake.core_suggestion_field.'.$field) {
            return $translated;
        }

        $review = __('intake.normalized_draft_review_field.'.$field);
        if ($review !== 'intake.normalized_draft_review_field.'.$field) {
            return $review;
        }

        return str_replace('_', ' ', $field);
    }

    private function sectionLabel(string $sectionKey): string
    {
        $translated = __('intake.normalized_draft_review_section.'.$sectionKey);
        if ($translated !== 'intake.normalized_draft_review_section.'.$sectionKey) {
            return $translated;
        }

        $catalog = config('field_catalog.section_labels.'.$sectionKey);
        if (is_string($catalog) && $catalog !== '') {
            return __($catalog);
        }

        return $sectionKey;
    }

    private function relationFieldLabel(string $collection, string $relationType): string
    {
        $relationLabel = __('intake.normalized_draft_relation_label.'.$relationType);
        if ($relationLabel === 'intake.normalized_draft_relation_label.'.$relationType) {
            $relationLabel = str_replace('_', ' ', $relationType);
        }

        $collectionLabel = $collection === 'siblings'
            ? __('intake.normalized_draft_review_section.siblings')
            : __('intake.normalized_draft_review_section.relatives');

        return trim($collectionLabel.' — '.$relationLabel);
    }
}
