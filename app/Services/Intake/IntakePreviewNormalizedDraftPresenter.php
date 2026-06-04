<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\MasterRelative;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use Throwable;

/**
 * Read-only preview view-model for normalized biodata draft (not persisted).
 */
final class IntakePreviewNormalizedDraftPresenter
{
    /** @var array<string, array<string, string>>|null */
    private ?array $relativeLabelCache = null;

    /** @var list<string> */
    private const FALLBACK_SECTION_ORDER = [
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
        'photo',
    ];

    /** @var list<string> */
    private const BASIC_INFO_FIELDS = [
        'full_name', 'gender', 'date_of_birth', 'birth_time', 'birth_place_text',
        'religion', 'caste', 'sub_caste', 'marital_status',
        'primary_contact_number_2', 'primary_contact_number_3',
        'address_line',
    ];

    /** @var list<string> */
    private const PHYSICAL_FIELDS = [
        'height_cm', 'complexion', 'blood_group', 'weight_kg', 'physical_build',
        'spectacles_lens', 'physical_condition', 'diet', 'smoking', 'drinking',
    ];

    /** @var list<string> */
    private const EDUCATION_CAREER_FIELDS = [
        'highest_education', 'occupation_title', 'company_name', 'annual_income',
        'work_location_text', 'specialization',
    ];

    /** @var list<string> */
    private const FAMILY_DETAIL_FIELDS = [
        'father_name', 'father_occupation', 'father_extra_info',
        'father_contact_1', 'father_contact_2', 'father_contact_3',
        'mother_name', 'mother_occupation', 'mother_extra_info',
        'mother_contact_1', 'mother_contact_2', 'mother_contact_3',
        'family_income', 'family_type', 'family_type_id', 'family_status', 'family_values',
    ];

    /** @var list<string> */
    private const HOROSCOPE_FIELDS = [
        'mangal_dosh_type',
        'navras_name',
        'devak',
        'kuldaivat',
        'gotra',
        'birth_weekday',
        'nakshatra',
        'charan',
        'rashi',
        'gan',
        'nadi',
        'yoni',
        'varna',
        'vashya',
        'rashi_lord',
    ];

    /**
     * @return array{
     *     available: bool,
     *     skipped_reason: ?string,
     *     build_error: ?string,
     *     review_flags_by_field: array<string, list<array{reason: string, raw: string}>>,
     *     sections: array<string, list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>>,
     *     raw_draft_json: ?string
     * }
     */
    public function present(string $text, bool $isBiodataText): array
    {
        if (! $isBiodataText) {
            return $this->unavailable('not_biodata_text');
        }

        if (trim($text) === '') {
            return $this->unavailable('empty_text');
        }

        try {
            $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
            $normalized = is_array($draft['normalized'] ?? null) ? $draft['normalized'] : [];
            $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
            $flags = is_array($draft['review_flags'] ?? null) ? $draft['review_flags'] : [];
            $reviewMap = $this->buildReviewMap($flags);

            $sections = array_replace($this->emptySections(), [
                'review_needed' => $this->reviewRows($flags),
                'basic-info' => $this->basicInfoRows($core, $normalized, $reviewMap),
                'physical' => $this->physicalRows($core, $reviewMap),
                'education-career' => $this->educationCareerRows($core, $reviewMap),
                'family-details' => $this->familyDetailsRows($core, $normalized, $reviewMap),
                'siblings' => $this->siblingRows($normalized, $reviewMap),
                'relatives' => $this->extendedFamilyRows($normalized, $reviewMap),
                'alliance' => $this->allianceRows($core, $normalized, $reviewMap),
                'property' => $this->propertyRows($normalized, $reviewMap, $text),
                'horoscope' => $this->horoscopeRows($normalized, $reviewMap),
                'about-me' => $this->aboutMeRows($normalized, $reviewMap),
                'about-preferences' => $this->preferenceRows($normalized, $reviewMap),
                'photo' => [],
            ]);

            return [
                'available' => true,
                'skipped_reason' => null,
                'build_error' => null,
                'review_flags_by_field' => $reviewMap,
                'sections' => $sections,
                'raw_draft_json' => config('app.debug')
                    ? json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'available' => false,
                'skipped_reason' => null,
                'build_error' => $e->getMessage(),
                'review_flags_by_field' => [],
                'sections' => $this->emptySections(),
                'raw_draft_json' => null,
            ];
        }
    }

    /**
     * @return array{
     *     available: bool,
     *     skipped_reason: ?string,
     *     build_error: ?string,
     *     review_flags_by_field: array<string, list<array{reason: string, raw: string}>>,
     *     sections: array<string, list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>>,
     *     raw_draft_json: ?string
     * }
     */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'skipped_reason' => $reason,
            'build_error' => null,
            'review_flags_by_field' => [],
            'sections' => $this->emptySections(),
            'raw_draft_json' => null,
        ];
    }

    /**
     * @return array<string, list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>>
     */
    private function emptySections(): array
    {
        return array_fill_keys($this->sectionKeys(), []);
    }

    /**
     * @return list<string>
     */
    private function sectionKeys(): array
    {
        $sectionOrder = config('field_catalog.section_order', self::FALLBACK_SECTION_ORDER);
        if (! is_array($sectionOrder) || $sectionOrder === []) {
            $sectionOrder = self::FALLBACK_SECTION_ORDER;
        }

        return array_values(array_unique(array_merge(['review_needed'], $sectionOrder)));
    }

    /**
     * @param  list<array<string, mixed>>  $flags
     * @return array<string, list<array{reason: string, raw: string}>>
     */
    private function buildReviewMap(array $flags): array
    {
        $map = [];
        foreach ($flags as $flag) {
            if (! is_array($flag)) {
                continue;
            }
            $field = $this->stringify($flag['field'] ?? null);
            if ($field === '') {
                continue;
            }
            $map[$field][] = [
                'reason' => $this->stringify($flag['reason'] ?? null),
                'raw' => $this->stringify($flag['raw'] ?? null),
            ];
            $suggested = $this->stringify($flag['suggested_section'] ?? null);
            if ($suggested !== '') {
                $last = array_key_last($map[$field]);
                if ($last !== null) {
                    $map[$field][$last]['suggested_section'] = $suggested;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}
     */
    private function displayRow(string $label, string $value, ?string $reviewFieldKey, array $reviewMap, array $meta = []): array
    {
        $needsReview = false;
        $reviewReason = null;
        $reviewHint = null;

        if ($reviewFieldKey !== null && isset($reviewMap[$reviewFieldKey])) {
            $needsReview = true;
            $reviewReason = $reviewMap[$reviewFieldKey][0]['reason'] ?? null;
            if ($reviewReason === 'candidate_name_from_heading_fallback' && $reviewFieldKey === 'core.full_name') {
                $reviewHint = __('intake.normalized_draft_full_name_fallback_hint');
            }
        }

        return array_merge([
            'label' => $label,
            'value' => $value,
            'field' => $reviewFieldKey,
            'needs_review' => $needsReview,
            'review_reason' => $reviewReason !== '' ? $reviewReason : null,
            'review_hint' => $reviewHint,
        ], $meta);
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function basicInfoRows(array $core, array $normalized, array $reviewMap): array
    {
        $rows = [];
        $coreAddressLine = $this->stringify($core['address_line'] ?? null);
        $parentAddressLines = $this->parentAddressLines($normalized);
        foreach (self::BASIC_INFO_FIELDS as $field) {
            $value = $this->stringify($core[$field] ?? null);
            if ($value === '') {
                continue;
            }
            $rows[] = $this->displayRow(
                $this->fieldLabel($field),
                $this->formatFieldValue($field, $value),
                'core.'.$field,
                $reviewMap
            );
        }

        $addressLabelTotals = [];
        foreach ($normalized['addresses'] ?? [] as $address) {
            if (! is_array($address)) {
                continue;
            }
            $addrLine = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($addrLine === '') {
                continue;
            }
            $type = $this->stringify($address['type'] ?? null);
            $label = $type !== ''
                ? $this->addressLabel($type)
                : __('intake.normalized_draft_address_row', ['n' => 1]);
            $addressLabelTotals[$label] = ($addressLabelTotals[$label] ?? 0) + 1;
        }
        $addressLabelCounts = [];
        foreach ($normalized['addresses'] ?? [] as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $addrLine = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($addrLine === '') {
                continue;
            }
            if ($coreAddressLine !== '' && $addrLine === $coreAddressLine) {
                continue;
            }
            if ($this->isParentAddressDuplicate($addrLine, $parentAddressLines)) {
                continue;
            }
            $type = $this->stringify($address['type'] ?? null);
            $label = $type !== ''
                ? $this->addressLabel($type)
                : __('intake.normalized_draft_address_row', ['n' => $index + 1]);
            $addressLabelCounts[$label] = ($addressLabelCounts[$label] ?? 0) + 1;
            $displayLabel = ($addressLabelTotals[$label] ?? 0) > 1
                ? $label.' '.$addressLabelCounts[$label]
                : $label;
            $rows[] = $this->displayRow(
                $displayLabel,
                $addrLine,
                null,
                $reviewMap
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function physicalRows(array $core, array $reviewMap): array
    {
        return $this->coreRows($core, self::PHYSICAL_FIELDS, $reviewMap);
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function educationCareerRows(array $core, array $reviewMap): array
    {
        $rows = $this->coreRows($core, self::EDUCATION_CAREER_FIELDS, $reviewMap);

        $hasAnnualIncome = false;
        foreach ($rows as $row) {
            if (($row['label'] ?? '') === $this->fieldLabel('annual_income')) {
                $hasAnnualIncome = true;
                break;
            }
        }

        if (! $hasAnnualIncome) {
            $derivedIncome = $this->annualIncomeFromSalaryPackage($this->stringify($core['salary_package_text'] ?? null));
            if ($derivedIncome !== null) {
                $rows[] = $this->displayRow(
                    $this->fieldLabel('annual_income'),
                    (string) $derivedIncome,
                    'core.annual_income',
                    $reviewMap
                );
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function familyDetailsRows(array $core, array $normalized, array $reviewMap): array
    {
        $rows = [];

        $fatherName = $this->stringify($core['father_name'] ?? null);
        if ($fatherName !== '') {
            $rows[] = $this->groupHeadingRow(
                app()->getLocale() === 'mr' ? 'वडील' : 'Father',
                $fatherName,
                false,
                null,
                $reviewMap
            );
            foreach ([
                'father_occupation' => $this->familyDetailLabel('occupation'),
                'father_extra_info' => $this->familyDetailLabel('extra'),
                'father_contact_1' => $this->familyDetailLabel('contact_1'),
                'father_contact_2' => $this->familyDetailLabel('contact_2'),
                'father_contact_3' => $this->familyDetailLabel('contact_3'),
            ] as $field => $displayLabel) {
                $value = $this->stringify($core[$field] ?? null);
                if ($value === '') {
                    continue;
                }
                $rows[] = $this->groupDetailRow(
                    $this->fieldLabel($field),
                    $this->formatFieldValue($field, $value),
                    'core.'.$field,
                    $reviewMap,
                    false,
                    ['display_label' => $displayLabel]
                );
            }
        }

        $motherName = $this->stringify($core['mother_name'] ?? null);
        if ($motherName !== '') {
            $rows[] = $this->groupHeadingRow(
                app()->getLocale() === 'mr' ? 'आई' : 'Mother',
                $motherName,
                $rows !== [],
                null,
                $reviewMap
            );
            foreach ([
                'mother_occupation' => $this->familyDetailLabel('occupation'),
                'mother_extra_info' => $this->familyDetailLabel('extra'),
                'mother_contact_1' => $this->familyDetailLabel('contact_1'),
                'mother_contact_2' => $this->familyDetailLabel('contact_2'),
                'mother_contact_3' => $this->familyDetailLabel('contact_3'),
            ] as $field => $displayLabel) {
                $value = $this->stringify($core[$field] ?? null);
                if ($value === '') {
                    continue;
                }
                $rows[] = $this->groupDetailRow(
                    $this->fieldLabel($field),
                    $this->formatFieldValue($field, $value),
                    'core.'.$field,
                    $reviewMap,
                    false,
                    ['display_label' => $displayLabel]
                );
            }
        }

        $addressRows = [];
        foreach ($normalized['parents_addresses'] ?? [] as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $addrLine = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($addrLine === '') {
                continue;
            }
            $addressRows[] = $this->groupDetailRow(
                $this->parentsAddressLabel($address, $index),
                $this->parentsAddressValue($address, $addrLine),
                null,
                $reviewMap,
                false,
                ['display_label' => $this->parentsAddressDetailLabel($address, $index)]
            );
        }
        if ($addressRows !== []) {
            $rows[] = $this->groupHeadingRow(
                $this->familyAddressHeading(),
                '',
                $rows !== [],
                null,
                $reviewMap
            );
            array_push($rows, ...$addressRows);
        }

        foreach ([
            'family_income',
            'family_type',
            'family_type_id',
            'family_status',
            'family_values',
        ] as $field) {
            $value = $this->stringify($core[$field] ?? null);
            if ($value === '') {
                continue;
            }
            $rows[] = $this->displayRow(
                $this->fieldLabel($field),
                $this->formatFieldValue($field, $value),
                'core.'.$field,
                $reviewMap
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function siblingRows(array $normalized, array $reviewMap): array
    {
        $rows = [];
        $siblings = is_array($normalized['siblings'] ?? null) ? $normalized['siblings'] : [];
        $relationTotals = $this->siblingRelationTotals($siblings);
        $relationOccurrences = [];
        foreach ($siblings as $sibling) {
            if (! is_array($sibling)) {
                continue;
            }
            $relationType = $this->stringify($sibling['relation_type'] ?? null);
            $relationOccurrences[$relationType] = ($relationOccurrences[$relationType] ?? 0) + 1;
            $prefix = $this->siblingRelationDisplayLabel($relationType, $relationTotals, $relationOccurrences[$relationType]);
            $name = $this->stringify($sibling['name'] ?? null);
            $rows[] = $this->groupHeadingRow($prefix, $name, $rows !== [], null, $reviewMap);
            array_push($rows, ...$this->groupedSiblingDetailRows($sibling, $prefix, $relationType, $reviewMap));
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $siblings
     * @return array<string, int>
     */
    private function siblingRelationTotals(array $siblings): array
    {
        $totals = [];
        foreach ($siblings as $sibling) {
            $relationType = $this->canonicalSiblingRelation($this->stringify($sibling['relation_type'] ?? null));
            $totals[$relationType] = ($totals[$relationType] ?? 0) + 1;
        }

        return $totals;
    }

    /**
     * @param  mixed  $siblings
     * @return list<array<string, mixed>>
     */
    private function expandedSiblingPreviewRows(mixed $siblings): array
    {
        if (! is_array($siblings)) {
            return [];
        }

        $rows = [];
        foreach ($siblings as $sibling) {
            if (! is_array($sibling)) {
                continue;
            }
            $this->appendSiblingPreviewRow($rows, $this->normalizeSiblingPreviewRow($sibling));
            $spouse = is_array($sibling['spouse'] ?? null) ? $sibling['spouse'] : [];
            if ($spouse === []) {
                continue;
            }
            $spouseRelation = $this->siblingSpouseRelationFor($this->stringify($sibling['relation_type'] ?? null));
            $spouseRow = $this->normalizeSiblingPreviewRow(array_merge($spouse, [
                'relation_type' => $spouseRelation,
                'marital_status' => 'married',
                'occupation' => $spouse['occupation'] ?? $spouse['occupation_title'] ?? null,
                'notes' => $spouse['notes'] ?? $spouse['additional_info'] ?? null,
            ]));
            $this->appendSiblingPreviewRow($rows, $spouseRow);
        }

        return array_values($rows);
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $row
     */
    private function appendSiblingPreviewRow(array &$rows, array $row): void
    {
        $key = $this->siblingPreviewDedupeKey($row);
        if ($key === '') {
            $rows[] = $row;

            return;
        }

        if (! isset($rows[$key])) {
            $rows[$key] = $row;

            return;
        }

        foreach ($row as $field => $value) {
            $incoming = $this->stringify($value);
            if ($incoming === '') {
                continue;
            }
            $current = $this->stringify($rows[$key][$field] ?? null);
            if ($current === '') {
                $rows[$key][$field] = $value;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function siblingPreviewDedupeKey(array $row): string
    {
        $relationType = $this->canonicalSiblingRelation($this->stringify($row['relation_type'] ?? null));
        $name = $this->normalizedPreviewDedupeText($this->stringify($row['name'] ?? null));
        if ($name !== '') {
            return $relationType.'|name|'.$name;
        }

        $contact = $this->normalizedPreviewDedupeText($this->stringify($row['contact_number'] ?? null));
        $address = $this->normalizedPreviewDedupeText($this->stringify($row['address_line'] ?? null));
        if ($contact !== '' || $address !== '') {
            return $relationType.'|contact|'.$contact.'|address|'.$address;
        }

        return '';
    }

    private function normalizedPreviewDedupeText(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeSiblingPreviewRow(array $row): array
    {
        $relationType = $this->canonicalSiblingRelation($this->stringify($row['relation_type'] ?? null));
        if (in_array($relationType, ['brother_wife', 'sister_husband'], true)) {
            $row['marital_status'] = 'married';
        }
        $row['relation_type'] = $relationType;
        if (! isset($row['occupation']) && isset($row['occupation_title'])) {
            $row['occupation'] = $row['occupation_title'];
        }
        if (! isset($row['notes']) && isset($row['additional_info'])) {
            $row['notes'] = $row['additional_info'];
        }

        return $row;
    }

    private function siblingSpouseRelationFor(string $relationType): string
    {
        return $this->canonicalSiblingRelation($relationType) === 'brother'
            ? 'brother_wife'
            : 'sister_husband';
    }

    private function canonicalSiblingRelation(string $relationType): string
    {
        $key = mb_strtolower(trim($relationType));

        return match ($key) {
            'brother', 'भाऊ' => 'brother',
            'sister', 'बहीण', 'बहिण', 'बहिणी' => 'sister',
            'brother_wife', 'brother wife', "brother's wife", 'भावजय', 'वहिनी', 'वाहिनी' => 'brother_wife',
            'sister_husband', 'sister husband', "sister's husband", 'दाजी', 'जावई', 'भाऊजी', 'भावजी' => 'sister_husband',
            default => $key !== '' ? str_replace(' ', '_', $key) : 'sibling',
        };
    }

    /**
     * @param  array<string, int>  $relationTotals
     */
    private function siblingRelationDisplayLabel(string $relationType, array $relationTotals, int $occurrence): string
    {
        $canonical = $this->canonicalSiblingRelation($relationType);
        $label = $this->localizedRelationDisplayLabel(
            'sibling',
            $canonical,
            ucfirst(str_replace('_', ' ', $relationType !== '' ? $relationType : 'Sibling'))
        );

        $numbered = match ($canonical) {
            'brother' => ($relationTotals['brother'] ?? 0) > 1,
            'sister' => ($relationTotals['sister'] ?? 0) > 1,
            'brother_wife' => ($relationTotals['brother'] ?? 0) > 1 || ($relationTotals['brother_wife'] ?? 0) > 1,
            'sister_husband' => ($relationTotals['sister'] ?? 0) > 1 || ($relationTotals['sister_husband'] ?? 0) > 1,
            default => ($relationTotals[$canonical] ?? 0) > 1,
        };

        if ($numbered && $canonical === 'brother_wife') {
            $base = $this->relativeMasterLabel('brother_wife', $canonical) ?? $label;

            return $base.' '.$occurrence;
        }
        if ($numbered && $canonical === 'sister_husband') {
            $base = $this->relativeMasterLabel('sister_husband', $canonical) ?? $label;

            return $base.' '.$occurrence;
        }

        return $numbered ? $label.' '.$occurrence : $label;
    }

    /**
     * @param  array<string, mixed>  $sibling
     * @return list<array{label: string, value: string, absolute_label?: bool}>
     */
    private function siblingDisplayParts(array $sibling, string $prefix, string $relationType): array
    {
        $parts = [];
        foreach ([
            'name',
            'marital_status',
            'contact_number',
            'contact_number_2',
            'contact_number_3',
            'occupation',
            'address_line',
            'location_display',
            'notes',
        ] as $field) {
            $value = $this->stringify($sibling[$field] ?? null);
            if ($value !== '') {
                $part = [
                    'label' => $this->siblingPartLabel($field, $prefix, $relationType),
                    'value' => $this->siblingPartValue($field, $value),
                ];
                if ($field === 'marital_status') {
                    $part['absolute_label'] = true;
                }
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  list<string>  $fields
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function coreRows(array $core, array $fields, array $reviewMap): array
    {
        $rows = [];
        $seenValues = [];
        foreach ($fields as $field) {
            $value = $this->stringify($core[$field] ?? null);
            if ($value === '') {
                continue;
            }
            $dedupeKey = $field.':'.$value;
            if (isset($seenValues[$dedupeKey])) {
                continue;
            }
            $seenValues[$dedupeKey] = true;
            $rows[] = $this->displayRow(
                $this->fieldLabel($field),
                $this->formatFieldValue($field, $value),
                'core.'.$field,
                $reviewMap
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function extendedFamilyRows(array $normalized, array $reviewMap): array
    {
        $rows = [];
        $relativesFlagged = isset($reviewMap['relatives']);
        $relationTotals = $this->paternalRelationTotals($normalized['relatives'] ?? []);
        $relationOccurrences = [];

        foreach ($normalized['relatives'] ?? [] as $relative) {
            if (! is_array($relative)) {
                continue;
            }
            if (! $this->isPaternalRelative($relative)) {
                continue;
            }
            $relationType = $this->stringify($relative['relation_type'] ?? null);
            $relationOccurrences[$relationType] = ($relationOccurrences[$relationType] ?? 0) + 1;
            $prefix = $this->relativeRelationDisplayLabel(
                $relationType,
                $relationTotals[$relationType] ?? 0,
                $relationOccurrences[$relationType]
            );
            $name = $this->stringify($relative['name'] ?? null);
            $rows[] = $this->groupHeadingRow($prefix, $name, $rows !== [], $relativesFlagged ? 'relatives' : null, $reviewMap);
            array_push($rows, ...$this->groupedRelativeDetailRows($relative, $prefix, $relativesFlagged ? 'relatives' : null, $reviewMap));
        }

        return $rows;
    }

    /**
     * @param  mixed  $relatives
     * @return array<string, int>
     */
    private function paternalRelationTotals(mixed $relatives): array
    {
        $totals = [];
        if (! is_array($relatives)) {
            return $totals;
        }

        foreach ($relatives as $relative) {
            if (! is_array($relative) || ! $this->isPaternalRelative($relative)) {
                continue;
            }
            $relationType = $this->stringify($relative['relation_type'] ?? null);
            if ($relationType !== '') {
                $totals[$relationType] = ($totals[$relationType] ?? 0) + 1;
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function allianceRows(array $core, array $normalized, array $reviewMap): array
    {
        $rows = [];
        $relativesFlagged = isset($reviewMap['relatives']);
        $relationTotals = $this->maternalRelationTotals($normalized['relatives'] ?? []);
        $relationOccurrences = [];

        foreach ($normalized['relatives'] ?? [] as $relative) {
            if (! is_array($relative) || ! $this->isMaternalRelative($relative)) {
                continue;
            }
            $relationType = $this->stringify($relative['relation_type'] ?? null);
            $relationOccurrences[$relationType] = ($relationOccurrences[$relationType] ?? 0) + 1;
            $prefix = $this->relativeRelationDisplayLabel(
                $relationType,
                $relationTotals[$relationType] ?? 0,
                $relationOccurrences[$relationType]
            );
            $name = $this->stringify($relative['name'] ?? null);
            $rows[] = $this->groupHeadingRow($prefix, $name, $rows !== [], $relativesFlagged ? 'relatives' : null, $reviewMap);
            array_push($rows, ...$this->groupedRelativeDetailRows($relative, $prefix, $relativesFlagged ? 'relatives' : null, $reviewMap));
        }

        $other = $this->stringify($core['other_relatives_text'] ?? null);
        if ($other !== '') {
            $rows[] = $this->groupHeadingRow($this->fieldLabel('other_relatives_text'), '', $rows !== [], 'core.other_relatives_text', $reviewMap);
            $rows[] = $this->groupDetailRow(
                $this->fieldLabel('other_relatives_text'),
                $other,
                'core.other_relatives_text',
                $reviewMap,
                true
            );
        }

        return $rows;
    }

    /**
     * @param  mixed  $relatives
     * @return array<string, int>
     */
    private function maternalRelationTotals(mixed $relatives): array
    {
        $totals = [];
        if (! is_array($relatives)) {
            return $totals;
        }

        foreach ($relatives as $relative) {
            if (! is_array($relative) || ! $this->isMaternalRelative($relative)) {
                continue;
            }
            $relationType = $this->stringify($relative['relation_type'] ?? null);
            if ($relationType !== '') {
                $totals[$relationType] = ($totals[$relationType] ?? 0) + 1;
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $relative
     */
    private function isPaternalRelative(array $relative): bool
    {
        return in_array($this->stringify($relative['relation_type'] ?? null), [
            'paternal_grandfather',
            'paternal_grandmother',
            'paternal_uncle',
            'wife_paternal_uncle',
            'paternal_aunt',
            'husband_paternal_aunt',
            'Cousin',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $relative
     * @return list<array{label: string, value: string}>
     */
    private function relativeDisplayParts(array $relative): array
    {
        $parts = [];
        foreach ([
            'name' => '',
            'contact_number' => $this->relationFieldLabel('contact_number'),
            'occupation' => $this->relationFieldLabel('occupation'),
            'address_line' => $this->relationFieldLabel('address_line'),
            'location_display' => $this->relationFieldLabel('location_display'),
            'notes' => $this->relationFieldLabel('notes'),
        ] as $field => $label) {
            $value = $this->stringify($relative[$field] ?? null);
            if ($value !== '') {
                $parts[] = ['label' => $label, 'value' => $value];
            }
        }

        if ($parts === []) {
            $relationType = $this->stringify($relative['relation_type'] ?? null);
            if ($relationType !== '') {
                $parts[] = ['label' => '', 'value' => $this->relativeRelationBaseLabel($relationType)];
            }
        }

        return $parts;
    }

    private function relativeRelationDisplayLabel(string $relationType, int $total, int $occurrence): string
    {
        $label = $this->relativeRelationBaseLabel($relationType);

        return $total > 1 ? $label.' '.$occurrence : $label;
    }

    private function relativeRelationBaseLabel(string $relationType): string
    {
        $group = $this->relativeGroupForType($relationType);
        if ($group !== null) {
            return $this->localizedRelationDisplayLabel($group, $relationType, str_replace('_', ' ', $relationType));
        }

        return str_replace('_', ' ', $relationType);
    }

    /**
     * @param  array<string, mixed>  $relative
     */
    private function isMaternalRelative(array $relative): bool
    {
        return in_array($this->stringify($relative['relation_type'] ?? null), [
            'maternal_address_ajol',
            'maternal_grandfather',
            'maternal_grandmother',
            'maternal_uncle',
            'wife_maternal_uncle',
            'maternal_aunt',
            'husband_maternal_aunt',
            'maternal_cousin',
        ], true);
    }

    private function relativeGroupForType(string $relationType): ?string
    {
        $type = trim($relationType);

        return match ($type) {
            'brother', 'sister', 'brother_wife', 'sister_husband' => 'sibling',
            'paternal_grandfather', 'paternal_grandmother', 'paternal_uncle', 'wife_paternal_uncle', 'paternal_aunt', 'husband_paternal_aunt', 'Cousin' => 'paternal',
            'maternal_address_ajol', 'maternal_grandfather', 'maternal_grandmother', 'maternal_uncle', 'wife_maternal_uncle', 'maternal_aunt', 'husband_maternal_aunt', 'maternal_cousin' => 'maternal',
            default => null,
        };
    }

    private function relativeMasterLabel(string $group, string $relationType): ?string
    {
        $map = $this->relativeLabelMap();

        return $map[$group][$relationType] ?? null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function relativeLabelMap(): array
    {
        if ($this->relativeLabelCache !== null) {
            return $this->relativeLabelCache;
        }

        $out = [];
        foreach (['sibling', 'paternal', 'maternal'] as $group) {
            $rows = MasterRelative::optionsForGroup($group);
            $groupMap = [];
            foreach ($rows as $row) {
                $value = trim((string) ($row['value'] ?? ''));
                $label = trim((string) ($row['label'] ?? ''));
                if ($value !== '' && $label !== '') {
                    $groupMap[$value] = $label;
                }
            }
            $out[$group] = $groupMap;
        }

        $this->relativeLabelCache = $out;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function aboutMeRows(array $normalized, array $reviewMap): array
    {
        $narrative = $normalized['extended_narrative'] ?? null;
        if (! is_array($narrative)) {
            $narrative = $normalized;
        }

        $value = $this->stringify($narrative['narrative_about_me'] ?? null);
        if ($value === '') {
            return [];
        }

        return [$this->displayRow($this->fieldLabel('narrative_about_me'), $value, null, $reviewMap)];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function preferenceRows(array $normalized, array $reviewMap): array
    {
        $preferences = $normalized['preferences'] ?? null;
        if (! is_array($preferences)) {
            return [];
        }

        $rows = [];
        foreach ($preferences as $field => $value) {
            $text = $this->stringify($value);
            if ($text === '') {
                continue;
            }
            $rows[] = $this->displayRow(
                $this->fieldLabel((string) $field),
                $text,
                null,
                $reviewMap
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function propertyRows(array $normalized, array $reviewMap, string $rawText): array
    {
        $rows = [];
        $assetRows = $this->previewPropertyAssets($normalized, $rawText);

        foreach ($assetRows as $index => $asset) {
            $rows[] = $this->displayRow(__('intake.normalized_draft_property_asset_row', ['n' => $index + 1]), '', null, $reviewMap);
            foreach ($this->propertyAssetDisplayParts($asset, true) as $part) {
                $rows[] = $this->displayRow($part['label'], $part['value'], null, $reviewMap);
            }
        }

        $rows[] = $this->displayRow(__('intake.normalized_draft_property_notes_label'), $this->previewPropertySectionNotes($normalized, $assetRows), null, $reviewMap);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return list<array<string, string>>
     */
    private function previewPropertyAssets(array $normalized, string $rawText): array
    {
        $assets = is_array($normalized['property_assets'] ?? null) ? $normalized['property_assets'] : [];
        $summary = is_array($normalized['property_summary'] ?? null)
            ? $this->stringify($normalized['property_summary']['summary_text'] ?? $normalized['property_summary']['summary_notes'] ?? null)
            : '';

        $previewAssets = [];
        for ($i = 0; $i < count($assets); $i++) {
            $asset = $assets[$i];
            if (! is_array($asset)) {
                continue;
            }
            if (isset($assets[$i + 1]) && is_array($assets[$i + 1]) && $this->shouldMergePropertyPreviewRows($asset, $assets[$i + 1])) {
                $merged = $assets[$i + 1];
                $merged['notes'] = trim($this->stringify($asset['notes'] ?? null).' '.$this->stringify($merged['notes'] ?? null));
                foreach ($this->previewPropertyAssetsFromNormalizedRow($merged) as $preview) {
                    $previewAssets[] = $preview;
                }
                $i++;

                continue;
            }
            foreach ($this->previewPropertyAssetsFromNormalizedRow($asset) as $preview) {
                $previewAssets[] = $preview;
            }
        }

        if ($previewAssets === [] && $summary !== '') {
            foreach ($this->previewPropertyAssetsFromTextBlock($summary) as $asset) {
                $previewAssets[] = $asset;
            }
        }

        if ($previewAssets === [] && $rawText !== '') {
            foreach ($this->previewPropertyAssetsFromTextBlock($this->extractPreviewPropertyText($rawText)) as $asset) {
                $previewAssets[] = $asset;
            }
        }

        return $this->mergePreviewLandAssets($this->propagateSharedPreviewPropertyLocations($previewAssets));
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return list<array<string, string>>
     */
    private function previewPropertyAssetsFromNormalizedRow(array $asset): array
    {
        $rawLocation = $this->stringify($asset['location'] ?? null);
        $rawNotes = $this->stringify($asset['notes'] ?? null);
        $raw = trim(implode(' ', array_filter([$rawNotes, $rawLocation], static fn (?string $v) => $v !== null && $v !== '')));
        if ($raw === '') {
            return [];
        }
        if ($this->isPreviewPropertyNoise($raw)) {
            return [];
        }

        if ($this->hasMixedPreviewPropertySignals($raw)) {
            $previewAssets = [];
            foreach ($this->splitMixedPreviewPropertyFragments($raw) as $fragment) {
                $preview = $this->previewPropertyAssetFromRaw($fragment, $asset);
                if ($preview !== null) {
                    $previewAssets[] = $preview;
                }
            }
            if ($previewAssets !== []) {
                return $previewAssets;
            }
        }

        $preview = $this->previewPropertyAssetFromRaw($raw, $asset);

        return $preview !== null ? [$preview] : [];
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, string>|null
     */
    private function previewPropertyAssetFromRaw(string $raw, array $asset): ?array
    {
        $normalizedRaw = $this->stripPreviewPropertyLabel($raw);
        if ($normalizedRaw === '') {
            $normalizedRaw = $raw;
        }

        $assetType = $this->previewPropertyAssetType($normalizedRaw, $asset);
        if ($assetType === null) {
            return null;
        }

        $rawLocation = $this->stringify($asset['location'] ?? null);
        $location = $this->previewPropertyLocation($normalizedRaw, $rawLocation, $assetType);
        $additionalInformation = $this->previewPropertyAdditionalInformation($asset, $normalizedRaw, $assetType, $location);
        $ownership = $this->previewPropertyOwnership($normalizedRaw, $asset);

        $preview = ['asset_type_label' => $assetType];
        $preview['location'] = $location;
        $preview['ownership_type_label'] = $ownership ?? '';
        $preview['additional_information'] = $additionalInformation;

        return $preview;
    }

    private function stripPreviewPropertyLabel(string $raw): string
    {
        $stripped = preg_replace('/^\s*(?:स्थावर\s*व\s*शेती|स्थावर\s*आणि\s*शेती|स्थावर\s*मिळकत|स्थायिक\s*मालमत्ता|मालमत्ता|प्रॉपर्टी|प्रोपर्टी|property|शेती|जमीन)\s*(?::\s*-\s*|[:\-–—]\s*)/ui', '', trim($raw));

        return trim((string) $stripped);
    }

    /**
     * @return list<array<string, string>>
     */
    private function previewPropertyAssetsFromTextBlock(string $summary): array
    {
        $summary = trim($summary);
        if ($summary === '') {
            return [];
        }

        $assets = [];
        foreach (preg_split('/\R/u', $summary) ?: [] as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }
            foreach ($this->previewPropertyAssetsFromNormalizedRow(['notes' => $segment]) as $preview) {
                $assets[] = $preview;
            }
        }

        return $assets;
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function previewPropertyAssetType(string $raw, array $asset): ?string
    {
        $text = OcrNormalize::normalizeDigits(mb_strtolower($raw));
        $existing = mb_strtolower($this->stringify($asset['asset_type_label'] ?? $asset['asset_type'] ?? $asset['asset_type_key'] ?? null));

        return match (true) {
            (bool) preg_match('/(?:\b[0-9]+\s*bhk\b|\bflat\b|\bflats\b|फ्लॅट|फ्लाट)/u', $text) => 'Flat',
            (bool) preg_match('/(?:\bplot\b|\bplots\b|प्लॉट)/u', $text) => 'Plot',
            (bool) preg_match('/(?:\bshop\b|\bshops\b|दुकान|दुकाने|गाळा|गाळे)/u', $text) => 'Shop',
            (bool) preg_match('/(?:\bcommercial\b|व्यावसायिक|\boffice\b|ऑफिस)/u', $text) => 'Commercial',
            (bool) preg_match('/(?:जमीन|शेती|बागायत|एकर|\bacre\b|\bacres\b|\bguntha\b|गुंठ)/u', $text) => 'Land',
            (bool) preg_match('/(?:घर|घरे|\bhouse\b|\bhouses\b|\bhome\b|\bhomes\b|bungalow|row\s*house)/u', $text) => 'House',
            $existing === 'land' || $existing === 'house' || $existing === 'vehicle' || $existing === 'gold' || $existing === 'financial' || $existing === 'other'
                => ucfirst($existing),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function previewPropertyOwnership(string $raw, array $asset): ?string
    {
        if (preg_match('/(?:स्वत[:ः]?\s*चे|स्वत:\s*चे|स्वतः\s*चे|स्वमालकीचे|own|owned)/ui', $raw)) {
            return 'Sole';
        }

        $existing = mb_strtolower($this->stringify($asset['ownership_type_label'] ?? $asset['ownership_type'] ?? $asset['ownership_type_key'] ?? null));

        return $existing === 'sole' ? 'Sole' : null;
    }

    private function previewPropertyLocation(string $raw, string $existingLocation, string $assetType): string
    {
        $location = $this->cleanPreviewPropertyLocation($existingLocation, $assetType);
        $rawLocation = $this->previewPropertyLocationFromRaw($raw, $assetType);
        if ($location === '') {
            return $rawLocation;
        }

        if ($rawLocation !== '' && mb_strlen($rawLocation) > mb_strlen($location) + 2) {
            return $rawLocation;
        }

        return $location;
    }

    private function previewPropertyLocationFromRaw(string $raw, string $assetType): string
    {
        if ($assetType === 'Land') {
            $landLocation = $this->previewLandLocationFromRaw($raw);
            if ($landLocation !== '') {
                return $landLocation;
            }
        }

        if (preg_match('/(.+?)\s+(?:मध्ये|येथे|in)(?:\s|$)/ui', $raw, $m)) {
            $location = $this->cleanPreviewPropertyLocation($m[1], $assetType);
            if ($location !== '') {
                return $location;
            }
        }

        if (preg_match('/[\{\(]([^{}\(\)]*(?:पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|अहमदनगर|नाशिक|बेळगाव|बेलगाव|नगर|गाव|वाडी|रोड)[^{}\(\)]*)[\}\)]/u', $raw, $m)) {
            $location = $this->cleanPreviewPropertyLocation($m[1], $assetType);
            if ($location !== '') {
                return $location;
            }
        }

        if (preg_match('/\(([^()]*(?:ता\.?|जि\.?|मु\.?\s*पो\.?|पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|नगर|गाव|वाडी|रोड)[^()]*)\)/u', $raw, $m)) {
            $location = $this->cleanPreviewPropertyLocation($m[1], $assetType);
            if ($location !== '') {
                return $location;
            }
        }

        return '';
    }

    private function cleanPreviewPropertyLocation(string $value, string $assetType): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/(?:स्थावर\s*मिळकत|स्थायिक\s*मालमत्ता|मालमत्ता|प्रॉपर्टी|प्रोपर्टी|property)/ui', '', $value) ?? $value;
        $value = preg_replace('/(?:स्थावर\s*व\s*शेती|स्थावर\s*आणि\s*शेती)/ui', '', $value) ?? $value;
        $value = preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $value) ?? $value;
        $value = preg_replace('/\(\s*(?:\d+|[०-९]+)\s*\)/u', '', $value) ?? $value;
        $value = preg_replace('/[\{\}]/u', '', $value) ?? $value;
        $value = preg_replace('/\b(?:मध्ये|येथे|in)\b/ui', '', $value) ?? $value;
        $value = preg_replace('/(?:स्वत[:ः]?\s*चे|स्वत:\s*चे|स्वतः\s*चे|स्वमालकीचे|own|owned)/ui', '', $value) ?? $value;
        $value = preg_replace('/\b[0-9]+\s*bhk\b/ui', '', OcrNormalize::normalizeDigits($value)) ?? $value;
        $value = preg_replace('/(?<!\p{L})(?:[0-9]+|[०-९]+)\s*(?:flat|flats|house|houses|shop|shops|plot|plots|फ्लॅट|फ्लाट|घरे|घर|दुकाने|दुकान|गाळे|गाळा|प्लॉट)\b/ui', '', $value) ?? $value;
        $value = preg_replace('/(?:flat|flats|house|houses|shop|shops|plot|plots|commercial|office|land|फ्लॅट|फ्लाट|घर|घरे|दुकान|दुकाने|गाळा|गाळे|प्लॉट|व्यावसायिक|ऑफिस|जमीन|शेती|बागायत|एकर|\bacre\b|\bacres\b|\bguntha\b|गुंठ)/ui', '', $value) ?? $value;
        $value = preg_replace('/^(?::\s*)+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/^[\s,.\-–—]+|[\s,.\-–—]+$/u', '', $value) ?? $value;

        if ($value !== '' && ! str_contains($value, ',')
            && preg_match('/^(.+?)\s+(पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|अहमदनगर|नाशिक|बेळगाव|बेलगाव)$/u', $value, $m)) {
            $value = trim($m[1]).', '.$m[2];
        }

        return $value;
    }

    private function previewPropertyAdditionalInformation(array $asset, string $raw, string $assetType, string $location): string
    {
        $existing = $this->stringify($asset['additional_information'] ?? null);
        if ($existing !== '') {
            return $existing;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        return match ($assetType) {
            'Flat' => $this->previewFlatNotes($raw),
            'House' => $this->previewCountNotes($raw, 'house'),
            'Shop' => $this->previewCountNotes($raw, 'shop'),
            'Plot' => $this->previewPlotNotes($raw),
            'Commercial' => $this->previewCommercialNotes($raw),
            'Land' => $this->previewLandNotes($raw),
            default => $this->previewGenericPropertyNotes($raw, $location),
        };
    }

    private function previewFlatNotes(string $raw): string
    {
        $parts = [];
        $quantity = null;
        $normalized = OcrNormalize::normalizeDigits($raw);
        if (preg_match('/(?<!\d)([0-9]+)\s*(?:[0-9]+\s*BHK\s*)?(?:flat|flats|फ्लॅट|फ्लाट)\b/ui', $normalized, $m)) {
            $quantity = $m[1];
        }
        if ($quantity === null && preg_match('/(?:flat|flats|फ्लॅट|फ्लाट)\s*\(([0-9]+)\)/ui', $normalized, $m)) {
            $quantity = $m[1];
        }
        $bhk = $this->previewBhkFromRaw($raw);

        if ($bhk !== null && $quantity === '1') {
            return $bhk.' '.$this->propertyGeneratedTerm('flat_singular');
        }

        if ($quantity !== null) {
            $parts[] = $quantity === '1'
                ? $this->propertyGeneratedTerm('flat_singular')
                : $quantity.' '.$this->propertyGeneratedTerm('flat_plural');
        }
        if ($bhk !== null) {
            $parts[] = $quantity === null ? $bhk.' '.$this->propertyGeneratedTerm('flat_singular') : $bhk;
        }
        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return preg_match('/(?:flat|flats|फ्लॅट|फ्लाट)/ui', $raw) ? $this->propertyGeneratedTerm('flat_singular') : '';
    }

    private function previewCommercialNotes(string $raw): string
    {
        $parts = [];
        if (preg_match('/(?:\boffice\b|ऑफिस)/ui', $raw)) {
            $parts[] = $this->propertyGeneratedTerm('office');
        }
        $quantity = $this->previewCountFromRaw($raw, '(?:commercial|office|व्यावसायिक|ऑफिस)');
        if ($quantity !== null && $parts === []) {
            $parts[] = $quantity.' '.$this->propertyGeneratedTerm('commercial');
        }

        return implode(', ', $parts);
    }

    private function previewLandNotes(string $raw): string
    {
        $parts = [];
        if (preg_match('/(?:farm\s*land|शेती|बागायत|agri|agriculture)/ui', $raw)) {
            $parts[] = $this->propertyGeneratedTerm('farm_land');
        }
        if (preg_match('/बागायत/u', $raw)) {
            $parts[] = $this->propertyGeneratedTerm('bagayat');
        }
        if (preg_match('/([0-9०-९]+(?:\.[0-9०-९]+)?|एक|दोन|तीन|चार|पाच|सहा|सात|आठ|नऊ|दहा)\s*(एकर|एक्कर|acre|acres|guntha|गुंठे?|गुंठा)/ui', $raw, $m)) {
            $parts[] = trim($this->previewNumberTokenToDisplay($m[1]).' '.$this->normalizeLandUnitLabel($m[2]));
        }

        return implode(', ', array_values(array_unique(array_filter($parts))));
    }

    private function previewPlotNotes(string $raw): string
    {
        if (preg_match('/([0-9०-९]+(?:\.[0-9०-९]+)?)\s*(गुंठे?|गुंठा|guntha|gunthas?)/ui', $raw, $m)) {
            return trim($m[1].' '.$m[2]);
        }

        return $this->previewCountNotes($raw, 'plot');
    }

    private function previewCountNotes(string $raw, string $type): string
    {
        $map = [
            'house' => ['pattern' => '(?:घर|घरे|house|houses)', 'label' => $this->propertyGeneratedTerm('house_plural')],
            'shop' => ['pattern' => '(?:shop|shops|दुकान|दुकाने|गाळा|गाळे)', 'label' => $this->propertyGeneratedTerm('shop_plural')],
            'plot' => ['pattern' => '(?:plot|plots|प्लॉट)', 'label' => $this->propertyGeneratedTerm('plot_plural')],
        ];
        $config = $map[$type] ?? null;
        if ($config === null) {
            return '';
        }

        $count = $this->previewCountFromRaw($raw, $config['pattern']);

        return $count !== null ? $count.' '.$config['label'] : '';
    }

    private function previewGenericPropertyNotes(string $raw, string $location): string
    {
        $value = $raw;
        if ($location !== '') {
            $value = str_replace($location, '', $value);
        }
        $value = preg_replace('/(?:स्थावर\s*मिळकत|स्थायिक\s*मालमत्ता|मालमत्ता|प्रॉपर्टी|प्रोपर्टी|property)/ui', '', $value) ?? $value;
        $value = preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $value) ?? $value;
        $value = preg_replace('/\(\s*(?:\d+|[०-९]+)\s*\)/u', '', $value) ?? $value;
        $value = preg_replace('/(?:स्वत[:ः]?\s*चे|स्वत:\s*चे|स्वतः\s*चे|स्वमालकीचे|own|owned)/ui', '', $value) ?? $value;

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return preg_replace('/^[\s,.\-–—]+|[\s,.\-–—]+$/u', '', $value) ?? $value;
    }

    private function previewCountFromRaw(string $raw, string $typePattern): ?string
    {
        $normalized = OcrNormalize::normalizeDigits($raw);
        if (preg_match('/(?<!\d)([0-9]+)\s*'.$typePattern.'/ui', $normalized, $m)) {
            return $m[1];
        }

        return null;
    }

    private function previewBhkFromRaw(string $raw): ?string
    {
        $normalized = OcrNormalize::normalizeDigits($raw);
        if (preg_match('/([0-9]+)\s*BHK/ui', $normalized, $m)) {
            return $m[1].' BHK';
        }

        return null;
    }

    private function hasMixedPreviewPropertySignals(string $raw): bool
    {
        $matches = 0;
        foreach ([
            '/(?:\b[0-9]+\s*bhk\b|\bflat\b|\bflats\b|फ्लॅट|फ्लाट)/ui',
            '/(?:\bplot\b|\bplots\b|प्लॉट)/ui',
            '/(?:जमीन|शेती|बागायत|एकर|\bacre\b|\bacres\b|\bguntha\b|गुंठ)/ui',
            '/(?:घर|घरे|\bhouse\b|\bhouses\b|\bhome\b|\bhomes\b|bungalow|row\s*house)/ui',
        ] as $pattern) {
            if (preg_match($pattern, $raw)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    /**
     * @return list<string>
     */
    private function splitMixedPreviewPropertyFragments(string $raw): array
    {
        $raw = $this->stripPreviewPropertyLabel($raw);
        $sharedLocation = '';
        if (preg_match('/(.+?)\s+येथे/u', $raw, $m)) {
            $sharedLocation = $this->cleanPreviewPropertyLocation($m[1], 'House');
        }

        $fragments = preg_split('/\s*,\s*|\s+व\s+/u', $raw) ?: [];

        foreach ($fragments as $index => $fragment) {
            $fragment = trim((string) $fragment);
            if ($fragment === '' || $sharedLocation === '') {
                $fragments[$index] = $fragment;
                continue;
            }
            if (! preg_match('/(?:घर|फ्लॅट|फ्लाट|प्लॉट|plot|flat|house)/ui', $fragment)) {
                $fragments[$index] = $fragment;
                continue;
            }
            if (preg_match('/(?:मध्ये|येथे|in|पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|अहमदनगर|नाशिक|बेळगाव|बेलगाव)/u', $fragment)) {
                $fragments[$index] = $fragment;
                continue;
            }
            $fragments[$index] = $sharedLocation.' येथे '.$fragment;
        }

        return array_values(array_filter(array_map(static function (string $fragment): string {
            return trim($fragment, " \t\n\r\0\x0B,.;:-");
        }, $fragments), static fn (string $fragment): bool => $fragment !== ''));
    }

    private function extractPreviewPropertyText(string $rawText): string
    {
        $lines = preg_split('/\R/u', $rawText) ?: [];
        $propertyLines = [];
        $capture = false;

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                $capture = false;
                continue;
            }

            $clean = preg_replace('/^\s*[-*•]+\s*/u', '', $line) ?? $line;
            $isStart = (bool) preg_match('/^(?:प्रॉपर्टी|प्रोपर्टी|स्थावर|शेती|जमीन|प्लॉट|फ्लॅट)\s*(?::\s*-\s*|[:\-–—]\s*)/u', $clean)
                || ((bool) preg_match('/(?:स्वत[:ः]?च(?:े|्या)|वडिलोपार्जित|मालकीच(?:े|्या))/u', $clean)
                    && (bool) preg_match('/(?:घर|फ्लॅट|फ्लाट|प्लॉट|जमीन|शेती|बागायत|एकर|\bacre\b|\bguntha\b|गुंठ)/ui', $clean));

            if ($isStart) {
                $propertyLines[] = $clean;
                $capture = true;
                continue;
            }

            if ($capture && preg_match('/^(?:\d+|[०-९]+)[\).]\s*/u', $clean)) {
                $propertyLines[] = $clean;
                continue;
            }

            $capture = false;
        }

        return implode("\n", $propertyLines);
    }

    private function previewLandLocationFromRaw(string $raw): string
    {
        if (preg_match('/[\{\(]([^{}\(\)]*(?:बेळगाव|बेलगाव|पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|अहमदनगर|नाशिक)[^{}\(\)]*)[\}\)]/u', $raw, $m)) {
            return $this->cleanPreviewPropertyLocation($m[1], 'Land');
        }

        if (preg_match('/(?:शेती|जमीन)[^,\n]*?\s+([\p{L}\p{M}]+(?:\s*\/\s*[\p{L}\p{M}]+)+)$/u', trim($raw), $m)) {
            return $this->cleanPreviewPropertyLocation($m[1], 'Land');
        }

        return '';
    }

    private function previewNumberTokenToDisplay(string $value): string
    {
        $value = trim($value);

        return match ($value) {
            'एक' => '1',
            'दोन' => '2',
            'तीन' => '3',
            'चार' => '4',
            'पाच' => '5',
            'सहा' => '6',
            'सात' => '7',
            'आठ' => '8',
            'नऊ' => '9',
            'दहा' => '10',
            default => $value,
        };
    }

    private function normalizeLandUnitLabel(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));

        return match ($unit) {
            'एक्कर' => 'एकर',
            default => $unit,
        };
    }

    private function isPreviewPropertyNoise(string $raw): bool
    {
        return preg_match('/(?:सद्या|नोकरी|व्यवसाय|super\s*visor|supervisor|quality|development|foundr|company|industry|उद्योग)/ui', $raw)
            && ! preg_match('/(?:घर|फ्लॅट|फ्लाट|प्लॉट|जमीन|शेती|बागायत|एकर|acre|guntha|गुंठ)/ui', preg_replace('/(?:स्थावर\s*व\s*शेती|स्थावर\s*आणि\s*शेती|स्थावर|शेती|जमीन|प्रॉपर्टी|प्रोपर्टी)/ui', '', $raw) ?? $raw);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $next
     */
    private function shouldMergePropertyPreviewRows(array $current, array $next): bool
    {
        $currentType = mb_strtolower($this->stringify($current['asset_type_key'] ?? $current['asset_type'] ?? $current['asset_type_label'] ?? null));
        $nextType = mb_strtolower($this->stringify($next['asset_type_key'] ?? $next['asset_type'] ?? $next['asset_type_label'] ?? null));
        $currentNotes = $this->stringify($current['notes'] ?? null);
        $nextNotes = $this->stringify($next['notes'] ?? null);

        if ($currentType !== 'other' || $currentNotes === '' || $nextNotes === '') {
            return false;
        }

        if (! preg_match('/(?:मध्ये|येथे|in|[0-9०-९]+)/ui', $currentNotes)) {
            return false;
        }

        return in_array($nextType, ['house', 'other'], true)
            && preg_match('/(?:\b[0-9]+\s*bhk\b|\bflat\b|\bflats\b|फ्लॅट|फ्लाट|\bcommercial\b|व्यावसायिक|\boffice\b|ऑफिस)/ui', OcrNormalize::normalizeDigits($nextNotes));
    }

    /**
     * @param  list<array<string, string>>  $assets
     * @return list<array<string, string>>
     */
    private function mergePreviewLandAssets(array $assets): array
    {
        $merged = [];
        foreach ($assets as $asset) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0
                && ($merged[$lastIndex]['asset_type_label'] ?? '') === 'Land'
                && ($asset['asset_type_label'] ?? '') === 'Land') {
                $last = $merged[$lastIndex];
                $lastLocation = $this->stringify($last['location'] ?? null);
                $nextLocation = $this->stringify($asset['location'] ?? null);
                $lastNotes = $this->stringify($last['additional_information'] ?? null);
                $nextNotes = $this->stringify($asset['additional_information'] ?? null);
                $lastOwnership = $this->stringify($last['ownership_type_label'] ?? null);
                $nextOwnership = $this->stringify($asset['ownership_type_label'] ?? null);

                if (($lastLocation === '' || $nextLocation === '' || $lastLocation === $nextLocation)
                    && ($lastOwnership === '' || $nextOwnership === '' || $lastOwnership === $nextOwnership)) {
                    if ($lastLocation === '' && $nextLocation !== '') {
                        $merged[$lastIndex]['location'] = $nextLocation;
                    }
                    if ($lastOwnership === '' && $nextOwnership !== '') {
                        $merged[$lastIndex]['ownership_type_label'] = $nextOwnership;
                    }
                    if ($lastNotes === '' && $nextNotes !== '') {
                        $merged[$lastIndex]['additional_information'] = $nextNotes;
                        continue;
                    }
                    if ($lastNotes !== '' && $nextNotes !== '' && $lastNotes !== $nextNotes) {
                        $merged[$lastIndex]['additional_information'] = $lastNotes.', '.$nextNotes;
                        continue;
                    }
                    if ($lastNotes !== '' || $nextNotes !== '' || $lastLocation === '' || $nextLocation === '') {
                        continue;
                    }
                }
            }

            $merged[] = $asset;
        }

        return $merged;
    }

    /**
     * @param  list<array<string, string>>  $assets
     * @return list<array<string, string>>
     */
    private function propagateSharedPreviewPropertyLocations(array $assets): array
    {
        for ($i = 1; $i < count($assets); $i++) {
            $previousType = $this->stringify($assets[$i - 1]['asset_type_label'] ?? null);
            $currentType = $this->stringify($assets[$i]['asset_type_label'] ?? null);
            $previousLocation = $this->stringify($assets[$i - 1]['location'] ?? null);
            $currentLocation = $this->stringify($assets[$i]['location'] ?? null);
            $previousAdditional = $this->stringify($assets[$i - 1]['additional_information'] ?? null);
            $currentAdditional = $this->stringify($assets[$i]['additional_information'] ?? null);

            if ($previousType === 'Flat'
                && $currentType === 'Flat'
                && $previousLocation === ''
                && $currentLocation !== ''
                && (str_contains($previousAdditional, 'BHK') || str_contains($currentAdditional, 'BHK'))) {
                $assets[$i - 1]['location'] = $currentLocation;
            }
        }

        return $assets;
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return list<array{label: string, value: string}>
     */
    private function propertyAssetDisplayParts(array $asset, bool $includeMissing = false): array
    {
        $parts = [];
        foreach ([
            'asset_type_label' => __('components.property.asset_type'),
            'asset_type' => __('components.property.asset_type'),
            'asset_type_key' => __('components.property.asset_type'),
            'location' => __('components.property.location'),
            'ownership_type_label' => __('components.property.ownership_type'),
            'ownership_type' => __('components.property.ownership_type'),
            'ownership_type_key' => __('components.property.ownership_type'),
            'additional_information' => __('components.property.additional_information'),
            'notes' => __('components.property.additional_information'),
        ] as $field => $label) {
            $value = $this->stringify($asset[$field] ?? null);
            if ($value === '' && ! $includeMissing) {
                continue;
            }
            if ($label === __('components.property.asset_type') && $this->hasPropertyPart($parts, __('components.property.asset_type'))) {
                continue;
            }
            if ($label === __('components.property.ownership_type') && $this->hasPropertyPart($parts, __('components.property.ownership_type'))) {
                continue;
            }
            if ($label === __('components.property.additional_information') && $this->hasPropertyPart($parts, __('components.property.additional_information'))) {
                continue;
            }
            $parts[] = ['label' => $label, 'value' => $this->propertyOptionDisplayValue($field, $value !== '' ? $value : $this->propertyNotMentionedValue())];
        }

        return $parts;
    }

    /**
     * @param  list<array{label: string, value: string}>  $parts
     */
    private function hasPropertyPart(array $parts, string $label): bool
    {
        foreach ($parts as $part) {
            if ($part['label'] === $label) {
                return true;
            }
        }

        return false;
    }

    private function propertyOptionDisplayValue(string $field, string $value): string
    {
        $key = mb_strtolower(trim($value));
        if (in_array($field, ['asset_type_label', 'asset_type', 'asset_type_key'], true)) {
            return [
                'flat' => __('intake.normalized_draft_property_option.flat'),
                'plot' => __('intake.normalized_draft_property_option.plot'),
                'shop' => __('intake.normalized_draft_property_option.shop'),
                'commercial' => __('intake.normalized_draft_property_option.commercial'),
                'land' => __('intake.normalized_draft_property_option.land'),
                'house' => __('intake.normalized_draft_property_option.house'),
                'vehicle' => __('intake.normalized_draft_property_option.vehicle'),
                'gold' => __('intake.normalized_draft_property_option.gold'),
                'financial' => __('intake.normalized_draft_property_option.financial'),
                'other' => __('intake.normalized_draft_property_option.other'),
            ][$key] ?? $value;
        }
        if (in_array($field, ['ownership_type_label', 'ownership_type', 'ownership_type_key'], true)) {
            return [
                'sole' => __('intake.normalized_draft_property_option.sole'),
                'joint' => __('intake.normalized_draft_property_option.joint'),
                'family' => __('intake.normalized_draft_property_option.family'),
                'other' => __('intake.normalized_draft_property_option.other'),
            ][$key] ?? $value;
        }

        return $value;
    }

    /**
     * @param  list<array<string, string>>  $assetRows
     */
    private function previewPropertySectionNotes(array $normalized, array $assetRows): string
    {
        $property = $normalized['property_summary'] ?? null;
        if (! is_array($property)) {
            return $this->propertyNotMentionedValue();
        }

        $summary = $this->stringify($property['summary_notes'] ?? $property['summary_text'] ?? null);
        if ($summary === '') {
            return $this->propertyNotMentionedValue();
        }

        if ($assetRows !== [] && preg_match('/(?:\b[0-9]+\s*bhk\b|\bflat\b|\bflats\b|\bhouse\b|\bhouses\b|\bshop\b|\bshops\b|\bplot\b|\bplots\b|\bcommercial\b|\boffice\b|फ्लॅट|फ्लाट|घर|घरे|दुकान|दुकाने|गाळा|गाळे|प्लॉट|व्यावसायिक|ऑफिस|जमीन|शेती|बागायत|एकर|\bacre\b|\bacres\b|\bguntha\b|गुंठ)/ui', OcrNormalize::normalizeDigits($summary))) {
            return $this->propertyNotMentionedValue();
        }

        return $summary;
    }

    private function propertyNotMentionedValue(): string
    {
        return __('intake.normalized_draft_not_mentioned');
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function horoscopeRows(array $normalized, array $reviewMap): array
    {
        $horoscope = $normalized['horoscope'] ?? null;
        if (! is_array($horoscope)) {
            return [];
        }

        $rows = [];
        $rawLines = is_array($horoscope['raw'] ?? null) ? $horoscope['raw'] : [];
        $explicitFieldPresence = $this->horoscopeExplicitFieldPresence($rawLines);
        $displayValues = [];

        foreach (self::HOROSCOPE_FIELDS as $field) {
            $text = $this->stringify($horoscope[$field] ?? null);
            if ($text === '') {
                continue;
            }
            if ($this->shouldSkipDuplicateHoroscopeField($field, $text, $horoscope, $explicitFieldPresence)) {
                continue;
            }
            $rows[] = $this->displayRow($this->horoscopeFieldLabel($field), $text, null, $reviewMap);
            $displayValues[] = $text;
        }

        foreach ($horoscope as $key => $value) {
            if ($key === 'raw' || in_array((string) $key, self::HOROSCOPE_FIELDS, true) || ! is_scalar($value)) {
                continue;
            }
            $text = $this->stringify($value);
            if ($text === '') {
                continue;
            }
            $rows[] = $this->displayRow($this->fieldLabel((string) $key), $text, null, $reviewMap);
            $displayValues[] = $text;
        }

        $coreBirthTime = $this->stringify($normalized['core']['birth_time'] ?? null);
        if ($coreBirthTime !== '') {
            $displayValues[] = $coreBirthTime;
        }

        foreach ($rawLines as $index => $line) {
            $text = $this->stringify($line);
            if ($text === '' || ! $this->shouldKeepHoroscopeRawLine($text, $displayValues)) {
                continue;
            }
            $rows[] = $this->displayRow(
                __('intake.normalized_draft_horoscope_line', ['n' => $index + 1]),
                $text,
                null,
                $reviewMap
            );
        }

        return $rows;
    }

    /**
     * @param  list<string>  $rawLines
     * @return array<string, bool>
     */
    private function horoscopeExplicitFieldPresence(array $rawLines): array
    {
        $present = [];
        $patterns = [
            'mangal_dosh_type' => '/मंगळ(?:िक|दोष)|mangal/ui',
            'navras_name' => '/नावरस|नावरस\s*नाव|रास\s*नाव|राशी\s*नाव|नावास\s*नाव/u',
            'devak' => '/देवक/u',
            'kuldaivat' => '/कुलदैवत|कुलदेवत|कलदैवत|कुलस्वामी|कुळस्वामी/u',
            'gotra' => '/गोत्र/u',
            'birth_weekday' => '/जन्मवार|वार/u',
            'nakshatra' => '/नक्षत्र|जन्मनक्षत्र/u',
            'charan' => '/चरण/u',
            'rashi' => '/जन्मरास|रास|राशी/u',
            'gan' => '/गण/u',
            'nadi' => '/नाडी|नाड\b/u',
            'yoni' => '/योनी/u',
            'varna' => '/वर्ण/u',
            'vashya' => '/वश्य|वैरवर्ग|vashya/ui',
            'rashi_lord' => '/^स्वामी\b|राशी\s*स्वामी|रास\s*स्वामी|rashi\s*lord/ui',
        ];

        foreach ($rawLines as $line) {
            $text = $this->stringify($line);
            if ($text === '') {
                continue;
            }
            foreach ($patterns as $field => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $present[$field] = true;
                }
            }
        }

        return $present;
    }

    /**
     * @param  array<string, mixed>  $horoscope
     * @param  array<string, bool>  $explicitFieldPresence
     */
    private function shouldSkipDuplicateHoroscopeField(string $field, string $text, array $horoscope, array $explicitFieldPresence): bool
    {
        if ($field === 'devak') {
            $kuldaivat = $this->stringify($horoscope['kuldaivat'] ?? null);
            if ($kuldaivat !== '' && $kuldaivat === $text && empty($explicitFieldPresence['devak']) && ! empty($explicitFieldPresence['kuldaivat'])) {
                return true;
            }
        }

        if ($field === 'kuldaivat') {
            $devak = $this->stringify($horoscope['devak'] ?? null);
            if ($devak !== '' && $devak === $text && empty($explicitFieldPresence['kuldaivat']) && ! empty($explicitFieldPresence['devak'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $displayValues
     */
    private function shouldKeepHoroscopeRawLine(string $line, array $displayValues): bool
    {
        $residual = OcrNormalize::normalizeDigits($line);
        $residual = str_replace(['व्याघ्र', 'चचत्रा'], ['वाघ', 'चित्रा'], $residual);
        $normalizedDisplayValues = [];
        foreach ($displayValues as $value) {
            $needle = OcrNormalize::normalizeDigits($value);
            $needle = str_replace(['व्याघ्र', 'चचत्रा'], ['वाघ', 'चित्रा'], $needle);
            if ($needle === '') {
                continue;
            }
            $normalizedDisplayValues[] = $needle;
        }

        usort($normalizedDisplayValues, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        foreach ($normalizedDisplayValues as $needle) {
            $residual = str_replace($needle, ' ', $residual);
        }

        $residual = preg_replace('/(?:नावरस\s*नाव|रास\s*नाव|राशी\s*नाव|नावास\s*नाव|जन्मरास|रास|राशी|जन्मनक्षत्र|नक्षत्र|देवक|कुलदैवत|कुलदेवत|कलदैवत|कुलस्वामी|कुळस्वामी|नाडी|नाड\b|गण|चरण|गोत्र|योनी|वर्ण|वश्य|वैरवर्ग|नावरस|मंगळ(?:िक|दोष)?|जन्मवार(?:\s*आणि\s*वेळ|\s*व\s*वेळ)?|राशी\s*स्वामी|रास\s*स्वामी|स्वामी|vashya|rashi\s*lord)/ui', ' ', $residual) ?? $residual;
        $residual = preg_replace('/[:\-–—,.;(){}\[\]\/\\\\]+/u', ' ', $residual) ?? $residual;
        $residual = preg_replace('/\b(?:and|or|time)\b/ui', ' ', $residual) ?? $residual;
        $residual = preg_replace('/(?:रक्त\s*गट|रक्तगट|रक[\x{094D}\x{200C}\s]*त\s*गट|blood\s*group|ब्लड\s*ग्रुप|ब्लड\s*ग्रप|कुंची|उंची|height|रंग|complexion)/ui', ' ', $residual) ?? $residual;
        $residual = preg_replace('/\b(?:A|B|AB|O)\s*[+-](?:VE)?\b/ui', ' ', $residual) ?? $residual;
        $residual = preg_replace('/^(?:गोरा|गोरी|निमगोरा|निमगोरी|सावळा|सावळी|गव्हाळ|fair|wheatish|dusky)$/ui', ' ', trim($residual)) ?? $residual;
        $residual = preg_replace('/\s+/u', ' ', trim($residual)) ?? trim($residual);

        return $residual !== '';
    }

    private function horoscopeFieldLabel(string $field): string
    {
        $translationKey = match ($field) {
            'mangal_dosh_type' => 'components.horoscope.mangal_dosh',
            'navras_name' => 'components.horoscope.navras_name',
            'devak' => 'components.horoscope.devak',
            'kuldaivat' => 'components.horoscope.kul',
            'gotra' => 'components.horoscope.gotra',
            'birth_weekday' => 'components.horoscope.birth_weekday',
            'nakshatra' => 'components.horoscope.nakshatra',
            'charan' => 'components.horoscope.charan',
            'rashi' => 'components.horoscope.rashi',
            'gan' => 'components.horoscope.gan',
            'nadi' => 'components.horoscope.nadi',
            'yoni' => 'components.horoscope.yoni',
            'varna' => 'components.horoscope.varna',
            'vashya' => 'components.horoscope.vashya',
            'rashi_lord' => 'components.horoscope.rashi_lord',
            default => null,
        };

        if ($translationKey !== null) {
            $translated = __($translationKey);
            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        return $this->fieldLabel($field);
    }

    /**
     * @param  list<array<string, mixed>>  $flags
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function reviewRows(array $flags): array
    {
        $rows = [];
        foreach ($flags as $index => $flag) {
            if (! is_array($flag)) {
                continue;
            }
            $field = $this->stringify($flag['field'] ?? null);
            $reason = $this->stringify($flag['reason'] ?? null);
            $raw = $this->stringify($flag['raw'] ?? null);
            $suggested = $this->stringify($flag['suggested_section'] ?? null);
            if ($field === '' && $reason === '' && $raw === '') {
                continue;
            }
            $valueParts = array_filter([
                $this->reviewReasonLabel($reason),
                $raw !== '' ? __('intake.normalized_draft_review_raw_prefix').' '.$raw : '',
                $suggested !== '' ? __('intake.normalized_draft_review_suggested_section_prefix').' '.$this->reviewSectionLabel($suggested) : '',
            ]);
            $row = $this->displayRow(
                $field !== '' ? $this->reviewFieldLabel($field) : __('intake.normalized_draft_review_row', ['n' => $index + 1]),
                $valueParts !== [] ? implode(' — ', $valueParts) : '—',
                $field !== '' ? $field : null,
                [$field => [['reason' => $reason, 'raw' => $raw]]]
            );
            $row['needs_review'] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    private function fieldLabel(string $field): string
    {
        $translated = __('intake.core_suggestion_field.'.$field);
        if ($translated !== 'intake.core_suggestion_field.'.$field) {
            return $translated;
        }

        $translated = __('profile.'.$field);
        if ($translated !== 'profile.'.$field) {
            return $translated;
        }

        return ucfirst(str_replace('_', ' ', $field));
    }

    private function formatFieldValue(string $field, string $value): string
    {
        if ($field === 'height_cm') {
            return $this->formatHeight($value);
        }

        if (in_array($field, ['gender_id', 'gender'], true)) {
            $normalized = mb_strtolower(trim($value));
            $translated = __('wizard.'.$normalized);
            if ($translated !== 'wizard.'.$normalized) {
                return $translated;
            }
        }

        return $value;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'होय' : 'नाही';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function formatHeight(string $value): string
    {
        if (! is_numeric($value)) {
            return $value;
        }
        $cm = (int) round((float) $value);
        if ($cm < 1) {
            return $value;
        }

        $totalInches = (int) round($cm / 2.54);
        $feet = intdiv($totalInches, 12);
        $inches = $totalInches % 12;

        return $feet.'\' '.$inches.'" ('.$cm.' cm)';
    }

    private function addressLabel(string $type): string
    {
        return match ($type) {
            'native' => __('intake.normalized_draft_address_native'),
            'current' => __('intake.normalized_draft_address_current'),
            default => __('intake.normalized_draft_address_typed', ['type' => $type]),
        };
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function parentsAddressLabel(array $address, int $index): string
    {
        $type = $this->stringify($address['address_type_key'] ?? $address['type'] ?? null);
        if ($type === '') {
            return __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]);
        }

        return __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]).' ('.$this->localizedAddressType($type).')';
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function parentsAddressValue(array $address, string $addrLine): string
    {
        $location = $this->stringify($address['display'] ?? $address['wizard_residence_display'] ?? null);
        if ($location === '') {
            return $addrLine;
        }

        return $addrLine !== '' ? ($addrLine.' · '.$location) : $location;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, true>
     */
    private function parentAddressLines(array $normalized): array
    {
        $lines = [];
        foreach ($normalized['parents_addresses'] ?? [] as $address) {
            if (! is_array($address)) {
                continue;
            }
            $line = mb_strtolower($this->stringify($address['address_line'] ?? $address['raw'] ?? null));
            if ($line !== '') {
                $lines[$line] = true;
            }
        }

        return $lines;
    }

    private function annualIncomeFromSalaryPackage(string $salaryPackageText): ?int
    {
        $normalized = OcrNormalize::normalizeDigits($salaryPackageText);
        if (! preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:LPA|LAC|लाख)/ui', $normalized, $m)) {
            return null;
        }

        return (int) round(((float) $m[1]) * 100000);
    }

    /**
     * @param  array<string, true>  $parentAddressLines
     */
    private function isParentAddressDuplicate(string $addressLine, array $parentAddressLines): bool
    {
        $needle = mb_strtolower(trim($addressLine));
        if ($needle === '') {
            return false;
        }
        foreach (array_keys($parentAddressLines) as $parentLine) {
            if ($parentLine === $needle || str_contains($parentLine, $needle) || str_contains($needle, $parentLine)) {
                return true;
            }
        }

        return false;
    }

    private function siblingPartLabel(string $field, string $prefix, string $relationType): string
    {
        return match ($field) {
            'name' => $this->relationFieldLabel('name'),
            'marital_status' => $this->siblingMaritalStatusLabel($prefix, $relationType),
            'contact_number' => $this->indexedRelationFieldLabel('contact_number', 1),
            'contact_number_2' => $this->indexedRelationFieldLabel('contact_number', 2),
            'contact_number_3' => $this->indexedRelationFieldLabel('contact_number', 3),
            'occupation' => $this->relationFieldLabel('occupation'),
            'address_line' => $this->relationFieldLabel('address_line'),
            'location_display' => $this->relationFieldLabel('location_display'),
            'notes' => $this->relationFieldLabel('notes'),
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    private function siblingPartValue(string $field, string $value): string
    {
        if ($field !== 'marital_status') {
            return $value;
        }

        $normalized = mb_strtolower(trim($value));

        return match (app()->getLocale()) {
            'mr' => match ($normalized) {
                'married' => 'विवाहित',
                'unmarried', 'single', 'never_married' => 'अविवाहित',
                'divorced' => 'घटस्फोट',
                'widowed' => 'विधुर/विधवा',
                default => $value,
            },
            default => $value,
        };
    }

    private function relationFieldLabel(string $field): string
    {
        return match ($field) {
            'name' => __('components.relation.name'),
            'contact_number' => __('components.relation.mobile'),
            'occupation' => __('components.relation.occupation'),
            'address_line' => __('components.relation.address'),
            'location_display' => __('components.relation.address'),
            'notes' => __('components.relation.additional_info'),
            default => $this->fieldLabel($field),
        };
    }

    private function indexedRelationFieldLabel(string $field, int $index): string
    {
        return $this->relationFieldLabel($field).' '.$index;
    }

    private function siblingMaritalStatusLabel(string $prefix, string $relationType): string
    {
        $canonical = $this->canonicalSiblingRelation($relationType);
        $normalizedPrefix = trim($prefix);

        if (app()->getLocale() === 'mr') {
            return match ($canonical) {
                'brother' => preg_match('/\s+\d+$/u', $normalizedPrefix) === 1
                    ? $normalizedPrefix.' ची विवाह माहिती'
                    : 'भावाची विवाह माहिती',
                'sister' => preg_match('/\s+\d+$/u', $normalizedPrefix) === 1
                    ? $normalizedPrefix.' ची विवाह माहिती'
                    : 'बहिणीची विवाह माहिती',
                default => $normalizedPrefix.' '.__('components.relation.spouse_details'),
            };
        }

        return $normalizedPrefix.' '.__('components.relation.married');
    }

    private function reviewFieldLabel(string $field): string
    {
        $translated = __('intake.normalized_draft_review_field.'.$field);
        if ($translated !== 'intake.normalized_draft_review_field.'.$field) {
            return $translated;
        }

        if (str_starts_with($field, 'core.')) {
            $coreField = substr($field, 5);
            $coreField = match ($coreField) {
                'gender' => 'gender_id',
                'religion' => 'religion_id',
                default => $coreField,
            };

            return $this->fieldLabel($coreField);
        }

        return $field;
    }

    private function reviewReasonLabel(string $reason): string
    {
        if ($reason === '') {
            return '';
        }

        $translated = __('intake.normalized_draft_review_reason.'.$reason);

        return $translated !== 'intake.normalized_draft_review_reason.'.$reason
            ? $translated
            : ucfirst(str_replace('_', ' ', $reason));
    }

    private function reviewSectionLabel(string $section): string
    {
        $translated = __('intake.normalized_draft_review_section.'.$section);

        return $translated !== 'intake.normalized_draft_review_section.'.$section
            ? $translated
            : $section;
    }

    private function localizedRelationDisplayLabel(string $group, string $relationType, string $default): string
    {
        $label = $this->relativeMasterLabel($group, $relationType);
        if ($label !== null && (! $this->shouldPreferTranslatedRelationLabel($label))) {
            return $label;
        }

        $translated = __('intake.normalized_draft_relation_label.'.$relationType);
        if ($translated !== 'intake.normalized_draft_relation_label.'.$relationType) {
            return $translated;
        }

        return $label ?? $default;
    }

    private function shouldPreferTranslatedRelationLabel(string $label): bool
    {
        return app()->getLocale() === 'mr' && preg_match('/^[\p{Latin}\p{Common}\p{Zs}()\'".,&\-0-9]+$/u', $label) === 1;
    }

    private function localizedAddressType(string $type): string
    {
        $normalized = mb_strtolower(trim($type));
        $translated = __('intake.normalized_draft_address_type.'.$normalized);
        if ($translated !== 'intake.normalized_draft_address_type.'.$normalized) {
            return $translated;
        }

        return $type;
    }

    private function propertyGeneratedTerm(string $key): string
    {
        $translated = __('intake.normalized_draft_property_generated.'.$key);

        return $translated !== 'intake.normalized_draft_property_generated.'.$key
            ? $translated
            : $key;
    }

    private function groupHeadingRow(string $label, string $value, bool $spacingBefore, ?string $reviewFieldKey = null, array $reviewMap = []): array
    {
        return $this->displayRow($label, $value, $reviewFieldKey, $reviewMap, [
            'row_variant' => 'group_heading',
            'spacing_before' => $spacingBefore,
            'display_heading_text' => $this->groupHeadingText($label, $value),
        ]);
    }

    private function groupDetailRow(string $label, string $value, ?string $reviewFieldKey, array $reviewMap, bool $valueOnly = false, array $meta = []): array
    {
        return $this->displayRow($label, $value, $reviewFieldKey, $reviewMap, array_merge([
            'row_variant' => $valueOnly ? 'group_detail_value_only' : 'group_detail',
        ], $meta));
    }

    private function groupHeadingText(string $prefix, string $name): string
    {
        return $name !== '' ? $prefix.' - '.$name : $prefix;
    }

    /**
     * @param  array<string, mixed>  $sibling
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array<string, mixed>>
     */
    private function groupedSiblingDetailRows(array $sibling, string $prefix, string $relationType, array $reviewMap): array
    {
        $rows = [];
        $marital = $this->stringify($sibling['marital_status'] ?? null);
        if ($marital !== '') {
            $rows[] = $this->groupDetailRow(
                $this->siblingMaritalStatusLabel($prefix, $relationType),
                $this->siblingPartValue('marital_status', $marital),
                null,
                $reviewMap,
                false,
                ['display_label' => $this->compactMaritalLabel($relationType)]
            );
        }

        $spouse = is_array($sibling['spouse'] ?? null) ? $sibling['spouse'] : [];
        if ($spouse !== []) {
            $spouseLabel = $this->compactSpouseHeading($relationType);
            $spouseName = $this->stringify($spouse['name'] ?? null);
            $spouseOccurrence = preg_match('/\s+(\d+)$/u', trim($prefix), $m) === 1 ? (int) $m[1] : 1;
            $rawSpousePrefix = $this->localizedRelationDisplayLabel(
                'sibling',
                $this->siblingSpouseRelationFor($relationType),
                $spouseLabel
            ).' '.$spouseOccurrence;
            if ($spouseName !== '') {
                $rows[] = $this->groupDetailRow(
                    $rawSpousePrefix.' '.$this->relationFieldLabel('name'),
                    $spouseName,
                    null,
                    $reviewMap,
                    false,
                    ['display_label' => $spouseLabel]
                );
            }
            foreach ([
                'occupation' => $spouseLabel.' '.$this->relationFieldLabel('occupation'),
                'occupation_title' => $spouseLabel.' '.$this->relationFieldLabel('occupation'),
                'address_line' => $spouseLabel.' '.$this->relationFieldLabel('address_line'),
                'location_display' => $spouseLabel.' '.$this->relationFieldLabel('location_display'),
                'contact_number' => $spouseLabel.' '.$this->relationFieldLabel('contact_number'),
                'contact_number_2' => $spouseLabel.' '.$this->indexedRelationFieldLabel('contact_number', 2),
                'contact_number_3' => $spouseLabel.' '.$this->indexedRelationFieldLabel('contact_number', 3),
                'notes' => $spouseLabel.' '.$this->relationFieldLabel('notes'),
                'additional_info' => $spouseLabel.' '.$this->relationFieldLabel('notes'),
            ] as $field => $label) {
                $value = $this->stringify($spouse[$field] ?? null);
                if ($value === '') {
                    continue;
                }
                $rows[] = $this->groupDetailRow(
                    $rawSpousePrefix.' '.$this->spouseRawFieldLabel($field),
                    $value,
                    null,
                    $reviewMap,
                    false,
                    ['display_label' => $label]
                );
            }
        }

        foreach ([
            'occupation' => $this->relationFieldLabel('occupation'),
            'contact_number' => $this->relationFieldLabel('contact_number'),
            'contact_number_2' => $this->indexedRelationFieldLabel('contact_number', 2),
            'contact_number_3' => $this->indexedRelationFieldLabel('contact_number', 3),
            'address_line' => $this->relationFieldLabel('address_line'),
            'location_display' => $this->relationFieldLabel('location_display'),
            'notes' => $this->relationFieldLabel('notes'),
        ] as $field => $label) {
            $value = $this->stringify($sibling[$field] ?? null);
            if ($value === '') {
                continue;
            }
            $rows[] = $this->groupDetailRow(
                $prefix.' '.$this->siblingPartLabel($field, $prefix, $relationType),
                $value,
                null,
                $reviewMap,
                false,
                ['display_label' => $label]
            );
        }

        if ($rows === []) {
            $rows[] = $this->groupDetailRow('', $prefix, null, $reviewMap, true);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $relative
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array<string, mixed>>
     */
    private function groupedRelativeDetailRows(array $relative, string $prefix, ?string $reviewFieldKey, array $reviewMap): array
    {
        $rows = [];
        foreach ([
            'occupation' => $this->relationFieldLabel('occupation'),
            'contact_number' => $this->relationFieldLabel('contact_number'),
            'address_line' => $this->relationFieldLabel('address_line'),
            'location_display' => $this->relationFieldLabel('location_display'),
            'notes' => $this->relationFieldLabel('notes'),
        ] as $field => $label) {
            $value = $this->stringify($relative[$field] ?? null);
            if ($value === '') {
                continue;
            }
            $rows[] = $this->groupDetailRow(
                $prefix.' '.$label,
                $value,
                $reviewFieldKey,
                $reviewMap,
                false,
                ['display_label' => $label]
            );
        }

        return $rows;
    }

    private function compactMaritalLabel(string $relationType): string
    {
        return app()->getLocale() === 'mr'
            ? 'वैवाहिक'
            : 'Married';
    }

    private function compactSpouseHeading(string $relationType): string
    {
        $spouseRelation = $this->siblingSpouseRelationFor($relationType);

        return $this->relativeRelationBaseLabel($spouseRelation);
    }

    private function familyHeading(string $type, string $name): string
    {
        $label = match (app()->getLocale()) {
            'mr' => $type === 'father' ? 'वडील' : 'आई',
            default => $type === 'father' ? 'Father' : 'Mother',
        };

        return $label.' - '.$name;
    }

    private function familyAddressHeading(): string
    {
        return app()->getLocale() === 'mr'
            ? 'पालकांचा पत्ता'
            : 'Parents address';
    }

    private function familyDetailLabel(string $key): string
    {
        return match (app()->getLocale()) {
            'mr' => match ($key) {
                'occupation' => 'व्यवसाय',
                'extra' => 'अतिरिक्त',
                'contact_1' => 'संपर्क 1',
                'contact_2' => 'संपर्क 2',
                'contact_3' => 'संपर्क 3',
                default => $key,
            },
            default => match ($key) {
                'occupation' => 'Occupation',
                'extra' => 'Additional',
                'contact_1' => 'Contact 1',
                'contact_2' => 'Contact 2',
                'contact_3' => 'Contact 3',
                default => $key,
            },
        };
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function parentsAddressDetailLabel(array $address, int $index): string
    {
        $type = mb_strtolower(trim($this->stringify($address['type'] ?? null)));

        return match (app()->getLocale()) {
            'mr' => match ($type) {
                'permanent' => 'कायम',
                'current' => 'सध्याचा',
                'parents' => __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]),
                default => $type !== '' ? $this->localizedAddressType($type) : __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]),
            },
            default => match ($type) {
                'permanent' => 'Permanent',
                'current' => 'Current',
                'parents' => __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]),
                default => $type !== '' ? $this->localizedAddressType($type) : __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]),
            },
        };
    }

    private function spouseRawFieldLabel(string $field): string
    {
        return match ($field) {
            'occupation', 'occupation_title' => $this->relationFieldLabel('occupation'),
            'address_line' => $this->relationFieldLabel('address_line'),
            'location_display' => $this->relationFieldLabel('location_display'),
            'contact_number' => $this->indexedRelationFieldLabel('contact_number', 1),
            'contact_number_2' => $this->indexedRelationFieldLabel('contact_number', 2),
            'contact_number_3' => $this->indexedRelationFieldLabel('contact_number', 3),
            'notes', 'additional_info' => $this->relationFieldLabel('notes'),
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }
}
