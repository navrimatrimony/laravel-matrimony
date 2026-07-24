<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\MasterRelative;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\Parsing\WizardRelationSchema;
use App\Support\LocalizedText;
use Throwable;

/**
 * Read-only preview view-model for normalized biodata draft (not persisted).
 */
final class IntakePreviewNormalizedDraftPresenter
{
    /** @var array<string, array<string, string>>|null */
    private ?array $relativeLabelCache = null;

    /** @var array<string, mixed>|null */
    private ?array $parsedSnapshot = null;

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
     *     detected_but_not_included: list<array{label: string, value: string, reason: ?string, suggested_section: ?string, draft_shows: ?string, source_line_no: ?int, missing_field: ?string, missing_value: ?string, correction_target: ?string}>,
     *     sections: array<string, list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>>,
     *     raw_draft_json: ?string
     * }
     */
    /**
     * @param  array<string, mixed>|null  $parsedSnapshot  Current intake parsed_json for apply eligibility.
     */
    public function present(string $text, bool $isBiodataText, ?array $parsedSnapshot = null): array
    {
        $this->parsedSnapshot = $parsedSnapshot;

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
            [$coverageFlags, $builderFlags] = $this->partitionReviewFlags($flags);
            $reviewMap = $this->buildReviewMap($flags);
            $detectedButNotIncluded = $this->detectedButNotIncludedRows(
                $coverageFlags,
                $normalized,
                is_array($draft['source_lines'] ?? null) ? $draft['source_lines'] : []
            );

            $sections = array_replace($this->emptySections(), [
                'review_needed' => $this->reviewRows($builderFlags, $normalized),
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
            $sections = $this->enrichSectionsWithApplyActions($sections, $detectedButNotIncluded);
            $draftParsedReconciliation = app(IntakeNormalizedDraftParsedReconciler::class)
                ->reconcile($normalized, $parsedSnapshot);

            $result = [
                'available' => true,
                'skipped_reason' => null,
                'build_error' => null,
                'review_flags_by_field' => $reviewMap,
                'detected_but_not_included' => $detectedButNotIncluded,
                'draft_parsed_reconciliation' => $draftParsedReconciliation,
                'sections' => $sections,
                'raw_draft_json' => json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $this->parsedSnapshot = null;

            return $result;
        } catch (Throwable $e) {
            report($e);

            $this->parsedSnapshot = null;

            return [
                'available' => false,
                'skipped_reason' => null,
                'build_error' => $e->getMessage(),
                'review_flags_by_field' => [],
                'detected_but_not_included' => [],
                'draft_parsed_reconciliation' => [
                    'available' => false,
                    'draft_not_in_parsed' => [],
                    'parsed_not_in_draft' => [],
                ],
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
     *     detected_but_not_included: list<array{label: string, value: string, reason: ?string, suggested_section: ?string, draft_shows: ?string, source_line_no: ?int, missing_field: ?string, missing_value: ?string, correction_target: ?string}>,
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
            'detected_but_not_included' => [],
            'draft_parsed_reconciliation' => [
                'available' => false,
                'draft_not_in_parsed' => [],
                'parsed_not_in_draft' => [],
            ],
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
            $reason = $this->stringify($flag['reason'] ?? null);
            if (str_starts_with($reason, 'coverage_')) {
                continue;
            }
            $map[$field][] = [
                'reason' => $reason,
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

        foreach ($this->userContactRows($core, $normalized, $reviewMap) as $contactRow) {
            $rows[] = $contactRow;
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
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array<string, mixed>>
     */
    private function userContactRows(array $core, array $normalized, array $reviewMap): array
    {
        $rows = [];
        $phones = [];
        $parentPhones = [];
        foreach ([
            'father_contact_number', 'father_contact_1', 'father_contact_2', 'father_contact_3',
            'mother_contact_number', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3',
        ] as $field) {
            $phone = $this->stringify($core[$field] ?? null);
            if ($phone !== '') {
                $parentPhones[$phone] = true;
            }
        }

        foreach ([
            ['core.primary_contact_number', $core['primary_contact_number'] ?? null],
            ['core.primary_contact_number_2', $core['primary_contact_number_2'] ?? null],
            ['core.primary_contact_number_3', $core['primary_contact_number_3'] ?? null],
        ] as [$field, $value]) {
            $phone = $this->stringify($value);
            if ($phone === '' || isset($parentPhones[$phone]) || in_array($phone, $phones, true)) {
                continue;
            }
            $phones[] = $phone;
        }

        foreach (($normalized['contacts'] ?? []) as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = $this->stringify($contact['phone_number'] ?? null);
            if ($phone === '' || isset($parentPhones[$phone]) || in_array($phone, $phones, true)) {
                continue;
            }
            $phones[] = $phone;
        }

        foreach ($phones as $index => $phone) {
            $field = match ($index) {
                0 => 'core.primary_contact_number',
                1 => 'core.primary_contact_number_2',
                2 => 'core.primary_contact_number_3',
                default => 'contacts.'.($index + 1).'.phone_number',
            };
            $label = __('intake.normalized_draft_user_contact', ['n' => $index + 1]);
            $rows[] = $this->displayRow($label, $phone, $field, $reviewMap);
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
                __('intake.normalized_draft_father'),
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
                __('intake.normalized_draft_mother'),
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
        return app(WizardRelationSchema::class)->isPaternalType($this->stringify($relative['relation_type'] ?? null));
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
        return app(WizardRelationSchema::class)->isMaternalType($this->stringify($relative['relation_type'] ?? null));
    }

    private function relativeGroupForType(string $relationType): ?string
    {
        $type = trim($relationType);

        $schema = app(WizardRelationSchema::class);

        return match (true) {
            $schema->isSiblingType($type) => 'sibling',
            $schema->isPaternalType($type) => 'paternal',
            $schema->isMaternalType($type) => 'maternal',
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
        $summary = is_array($normalized['property_summary'] ?? null)
            ? $this->stringify($normalized['property_summary']['summary_text'] ?? $normalized['property_summary']['summary_notes'] ?? null)
            : '';
        $rawPropertyText = trim($this->extractPreviewPropertyText($rawText));
        $text = $rawPropertyText !== '' ? $rawPropertyText : $summary;
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        return [
            $this->displayRow(__('components.property.property_details'), $text, 'core.property_details', $reviewMap),
        ];
    }

    private function shouldShowPropertySummaryRow(string $summary): bool
    {
        return (bool) preg_match('/(?:ट्रॅक्टर|tractor|वाहन|vehicle|गाडी|car|bike|मशीन|machine|पोकॉल|पोकलेन|poclain|jcb)/ui', OcrNormalize::normalizeDigits($summary));
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

        $coreBirthTime = $this->stringify($normalized['core']['birth_time'] ?? null);
        if ($coreBirthTime !== '') {
            $displayValues[] = $coreBirthTime;
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
     * @param  array<string, mixed>  $normalized
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string, source_line_no: ?int, source_text: ?string, missing_field: ?string, missing_value: ?string, correction_target: ?string, suggested_section: ?string}>
     */
    private function reviewRows(array $flags, array $normalized): array
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
            $sourceLineNo = (int) ($flag['source_line_no'] ?? 0);
            $sourceText = $this->stringify($flag['source_text'] ?? null);
            if ($field === '' && $reason === '' && $raw === '') {
                continue;
            }
            $rawLine = $this->preferredFlagRawLine($sourceText, $raw);
            $guidance = $this->correctionGuidanceForFlag($flag, $normalized);
            $row = $this->displayRow(
                $field !== '' ? $this->reviewFieldLabel($field) : __('intake.normalized_draft_review_row', ['n' => $index + 1]),
                $this->reviewReasonLabel($reason),
                $field !== '' ? $field : null,
                [$field => [['reason' => $reason, 'raw' => $raw]]]
            );
            $row['needs_review'] = true;
            $row['source_line_no'] = $sourceLineNo > 0 ? $sourceLineNo : null;
            $row['source_text'] = $rawLine !== '' ? $rawLine : null;
            $row['suggested_section'] = $suggested !== '' ? $this->reviewSectionLabel($suggested) : null;
            $row['missing_field'] = $guidance['missing_field'];
            $row['missing_value'] = $guidance['missing_value'];
            $row['correction_target'] = $guidance['correction_target'];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $flags
     * @param  array<string, mixed>  $normalized
     * @param  list<array<string, mixed>>  $sourceLines
     * @return list<array{label: string, value: string, reason: ?string, suggested_section: ?string, draft_shows: ?string, source_line_no: ?int, missing_field: ?string, missing_value: ?string, correction_target: ?string}>
     */
    private function detectedButNotIncludedRows(array $flags, array $normalized, array $sourceLines): array
    {
        $rows = [];
        $seen = [];

        foreach ($flags as $flag) {
            if (! is_array($flag)) {
                continue;
            }

            $raw = $this->stringify($flag['raw'] ?? null);
            if ($raw === '') {
                continue;
            }

            if ($this->coverageFlagIsFullyMapped($flag, $normalized)) {
                continue;
            }

            $field = $this->stringify($flag['field'] ?? null);
            $reason = $this->stringify($flag['reason'] ?? null);
            $suggested = $this->stringify($flag['suggested_section'] ?? null);
            $sourceLineNo = (int) ($flag['source_line_no'] ?? 0);
            $sourceText = $this->stringify($flag['source_text'] ?? null);
            $guidance = $this->correctionGuidanceForFlag($flag, $normalized);

            $this->appendDetectedButNotIncludedRow(
                $rows,
                $seen,
                $field !== '' ? $this->reviewFieldLabel($field) : __('intake.normalized_draft_detected_item_label'),
                $sourceText !== '' ? $sourceText : $raw,
                $this->coverageFlagExplanation($flag, $normalized),
                $suggested !== '' ? $this->reviewSectionLabel($suggested) : null,
                $this->draftSnapshotForCoverageField($field, $normalized),
                $sourceLineNo > 0 ? $sourceLineNo : null,
                $guidance['missing_field'],
                $guidance['missing_value'],
                $guidance['correction_target'],
                $suggested !== '' ? $suggested : null,
                $guidance['apply_field'],
                $guidance['apply_value'],
                $guidance['can_apply'],
                $guidance['apply_reason']
            );
        }

        $horoscope = $normalized['horoscope'] ?? null;
        if (! is_array($horoscope)) {
            return $rows;
        }

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
            $displayValues[] = $text;
        }

        $coreBirthTime = $this->stringify($normalized['core']['birth_time'] ?? null);
        if ($coreBirthTime !== '') {
            $displayValues[] = $coreBirthTime;
        }

        foreach ($horoscope as $key => $value) {
            if ($key === 'raw' || in_array((string) $key, self::HOROSCOPE_FIELDS, true) || ! is_scalar($value)) {
                continue;
            }
            $text = $this->stringify($value);
            if ($text === '') {
                continue;
            }

            $this->appendDetectedButNotIncludedRow(
                $rows,
                $seen,
                $this->fieldLabel((string) $key),
                $text,
                $this->detectedReasonWithLineNumber(
                    __('intake.normalized_draft_detected_not_included_reason'),
                    $this->findSourceLineNumber($sourceLines, $text)
                ),
                __('intake.normalized_draft_section_horoscope_religious'),
                null,
                $this->findSourceLineNumber($sourceLines, $text),
                $this->fieldLabel((string) $key),
                $text,
                __('intake.normalized_draft_section_horoscope_religious').' → '.$this->fieldLabel((string) $key)
            );
        }

        foreach ($rawLines as $line) {
            $text = $this->stringify($line);
            if ($text === '' || ! $this->shouldKeepHoroscopeRawLine($text, $displayValues)) {
                continue;
            }

            $lineNo = $this->findSourceLineNumber($sourceLines, $text);
            $this->appendDetectedButNotIncludedRow(
                $rows,
                $seen,
                __('intake.normalized_draft_detected_item_label'),
                $text,
                $this->detectedReasonWithLineNumber(
                    __('intake.normalized_draft_detected_not_included_reason'),
                    $lineNo
                ),
                __('intake.normalized_draft_section_horoscope_religious'),
                null,
                $lineNo
            );
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $sourceLines
     */
    private function findSourceLineNumber(array $sourceLines, string $text): ?int
    {
        $needle = trim(OcrNormalize::normalizeDigits($text));
        if ($needle === '') {
            return null;
        }

        foreach ($sourceLines as $sourceLine) {
            if (! is_array($sourceLine)) {
                continue;
            }

            $raw = trim(OcrNormalize::normalizeDigits((string) ($sourceLine['raw'] ?? '')));
            $normalized = trim(OcrNormalize::normalizeDigits((string) ($sourceLine['normalized'] ?? '')));
            if ($raw === '' && $normalized === '') {
                continue;
            }
            if ($raw === $needle || $normalized === $needle || str_contains($raw, $needle) || str_contains($normalized, $needle)) {
                $lineNo = (int) ($sourceLine['line_no'] ?? 0);

                return $lineNo > 0 ? $lineNo : null;
            }
        }

        return null;
    }

    private function detectedReasonWithLineNumber(string $reason, ?int $lineNo): string
    {
        $reason = trim($reason);
        if ($lineNo === null || $lineNo <= 0) {
            return $reason;
        }

        return trim($reason.' (Line '.$lineNo.')');
    }

    /**
     * @param  list<array{label: string, value: string, reason: ?string, suggested_section: ?string, draft_shows: ?string, source_line_no: ?int, missing_field: ?string, missing_value: ?string, correction_target: ?string}>  $rows
     * @param  array<string, bool>  $seen
     */
    private function appendDetectedButNotIncludedRow(
        array &$rows,
        array &$seen,
        string $label,
        string $value,
        ?string $reason,
        ?string $suggestedSection,
        ?string $draftShows = null,
        ?int $sourceLineNo = null,
        ?string $missingField = null,
        ?string $missingValue = null,
        ?string $correctionTarget = null,
        ?string $targetSection = null,
        ?string $applyField = null,
        ?string $applyValue = null,
        bool $canApply = false,
        ?string $applyReason = null
    ): void {
        $key = mb_strtolower(trim($label.'|'.$value));
        if ($key === '' || isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $rows[] = [
            'label' => $label,
            'value' => $value,
            'reason' => $reason,
            'draft_shows' => $draftShows,
            'suggested_section' => $suggestedSection,
            'target_section' => $targetSection,
            'source_line_no' => $sourceLineNo,
            'missing_field' => $missingField,
            'missing_value' => $missingValue,
            'correction_target' => $correctionTarget,
            'apply_field' => $applyField,
            'apply_value' => $applyValue,
            'can_apply' => $canApply,
            'apply_reason' => $canApply ? ($applyReason ?? 'detected_not_included') : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $flags
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function partitionReviewFlags(array $flags): array
    {
        $coverage = [];
        $builder = [];
        foreach ($flags as $flag) {
            if (! is_array($flag)) {
                continue;
            }
            $reason = (string) ($flag['reason'] ?? '');
            if (str_starts_with($reason, 'coverage_')) {
                $coverage[] = $flag;

                continue;
            }
            $builder[] = $flag;
        }

        return [$coverage, $builder];
    }

    private function preferredFlagRawLine(string $sourceText, string $raw): string
    {
        $sourceText = trim($sourceText);
        $raw = trim($raw);
        if ($sourceText !== '') {
            return $sourceText;
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $flag
     * @param  array<string, mixed>  $normalized
     */
    private function coverageFlagExplanation(array $flag, array $normalized): string
    {
        $reason = (string) ($flag['reason'] ?? '');
        $field = (string) ($flag['field'] ?? '');
        $sourceText = trim($this->preferredFlagRawLine(
            $this->stringify($flag['source_text'] ?? null),
            $this->stringify($flag['raw'] ?? null)
        ));
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];

        if ($field === 'core.caste' && $sourceText !== '') {
            $religion = trim((string) ($core['religion'] ?? ''));
            $caste = trim((string) ($core['caste'] ?? ''));
            $subCaste = trim((string) ($core['sub_caste'] ?? ''));
            if (preg_match('/(\d+\s*कुळी|९६\s*कुळी)/u', $sourceText) && $subCaste === '') {
                return __('intake.normalized_draft_coverage_partial_caste', [
                    'religion' => $religion !== '' ? $religion : __('intake.normalized_draft_empty_field'),
                    'caste' => $caste !== '' ? $caste : __('intake.normalized_draft_empty_field'),
                    'missing' => __('intake.normalized_draft_sub_caste_label'),
                ]);
            }
        }

        if ($field === 'core.occupation_title' && $sourceText !== '') {
            $company = trim((string) ($core['company_name'] ?? ''));
            $location = trim((string) ($core['work_location_text'] ?? ''));
            $title = trim((string) ($core['occupation_title'] ?? ''));
            if ($company !== '' || $location !== '') {
                return __('intake.normalized_draft_coverage_partial_occupation', [
                    'title' => $title !== '' ? $title : __('intake.normalized_draft_empty_field'),
                    'company' => $company !== '' ? $company : __('intake.normalized_draft_empty_field'),
                    'location' => $location !== '' ? $location : __('intake.normalized_draft_empty_field'),
                ]);
            }
        }

        if ($reason === 'coverage_wrong_section_fact') {
            return __('intake.normalized_draft_coverage_wrong_section');
        }
        if ($reason === 'coverage_duplicate_fact') {
            return __('intake.normalized_draft_coverage_duplicate');
        }
        if ($reason === 'coverage_mixed_fact') {
            return __('intake.normalized_draft_coverage_mixed');
        }
        if ($reason === 'coverage_missing_fact') {
            return __('intake.normalized_draft_coverage_missing');
        }

        $label = $this->reviewReasonLabel($reason);

        return $label !== '' ? $label : __('intake.normalized_draft_coverage_missing');
    }

    /**
     * @param  array<string, mixed>  $flag
     * @param  array<string, mixed>  $normalized
     * @return array{missing_field: ?string, missing_value: ?string, correction_target: ?string, apply_field: ?string, apply_value: ?string, can_apply: bool}
     */
    private function correctionGuidanceForFlag(array $flag, array $normalized): array
    {
        $field = (string) ($flag['field'] ?? '');
        $reason = (string) ($flag['reason'] ?? '');
        $suggested = (string) ($flag['suggested_section'] ?? '');
        $sourceText = trim($this->preferredFlagRawLine(
            $this->stringify($flag['source_text'] ?? null),
            $this->stringify($flag['raw'] ?? null)
        ));
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $sectionLabel = $suggested !== '' ? $this->reviewSectionLabel($suggested) : '';

        if (($field === 'core.caste' || $field === 'core.sub_caste')
            && preg_match('/(\d+\s*कुळी|९६\s*कुळी)/u', $sourceText, $matches)
            && trim((string) ($core['sub_caste'] ?? '')) === '') {
            $targetField = $this->fieldLabel('sub_caste');

            return $this->finalizeCorrectionGuidance([
                'missing_field' => $targetField,
                'missing_value' => trim($matches[1]),
                'correction_target' => $this->correctionTargetLabel(
                    $sectionLabel !== '' ? $sectionLabel : $this->reviewSectionLabel('basic-info'),
                    $targetField
                ),
            ], $flag, $normalized, 'core.sub_caste');
        }

        if ($field === 'core.occupation_title' && $sourceText !== '') {
            $targetField = $this->fieldLabel('occupation_title');
            $missingValue = trim((string) ($core['occupation_title'] ?? ''));
            if ($missingValue === '') {
                $missingValue = $this->extractValueFromSourceLine($sourceText);
            }

            return $this->finalizeCorrectionGuidance([
                'missing_field' => $targetField,
                'missing_value' => $missingValue !== '' ? $missingValue : null,
                'correction_target' => $this->correctionTargetLabel(
                    $sectionLabel !== '' ? $sectionLabel : $this->reviewSectionLabel('education-career'),
                    $targetField
                ),
            ], $flag, $normalized, 'core.occupation_title');
        }

        if ($reason === 'mixed_field_value' && $sectionLabel !== '') {
            return $this->finalizeCorrectionGuidance([
                'missing_field' => __('intake.normalized_draft_correction_multiple_fields'),
                'missing_value' => $sourceText !== '' ? $sourceText : null,
                'correction_target' => __('intake.normalized_draft_correction_split_into_section', ['section' => $sectionLabel]),
            ], $flag, $normalized, '');
        }

        if ($field !== '' && ! str_starts_with($field, 'review.')) {
            $targetFieldLabel = $this->reviewFieldLabel($field);
            if ($sectionLabel === '') {
                $sectionLabel = $this->defaultSectionLabelForField($field);
            }
            if ($sectionLabel !== '' && $targetFieldLabel !== '') {
                $missingValue = $this->resolveMissingValueForFlag($flag, $normalized, $field);
                $applyField = $this->resolveApplyFieldPath($field);

                return $this->finalizeCorrectionGuidance([
                    'missing_field' => $targetFieldLabel,
                    'missing_value' => $missingValue !== '' ? $missingValue : null,
                    'correction_target' => $this->correctionTargetLabel($sectionLabel, $targetFieldLabel),
                ], $flag, $normalized, $applyField);
            }
        }

        return $this->finalizeCorrectionGuidance([
            'missing_field' => null,
            'missing_value' => null,
            'correction_target' => null,
        ], $flag, $normalized, '');
    }

    /**
     * @param  array{missing_field: ?string, missing_value: ?string, correction_target: ?string}  $guidance
     * @param  array<string, mixed>  $flag
     * @param  array<string, mixed>  $normalized
     * @return array{missing_field: ?string, missing_value: ?string, correction_target: ?string, apply_field: ?string, apply_value: ?string, can_apply: bool, apply_reason: ?string}
     */
    private function finalizeCorrectionGuidance(array $guidance, array $flag, array $normalized, string $defaultApplyField): array
    {
        $applyField = $this->resolveApplyFieldPath($defaultApplyField !== '' ? $defaultApplyField : (string) ($flag['field'] ?? ''));
        $applyValue = $this->resolveApplyValue($flag, $normalized, $guidance, $applyField);
        $canApply = IntakeNormalizedDraftCorrectionApplier::supportsField($applyField)
            && $applyValue !== ''
            && ! $this->isNegativeSiblingAssertion($applyField, $applyValue)
            && ! $this->coverageFlagIsFullyMapped($flag, $normalized)
            && $this->applyTargetNeedsCorrection($applyField, $applyValue, $normalized);
        $applyReason = $canApply
            ? $this->resolveApplyReason($applyField, $applyValue, $normalized)
            : null;

        return array_merge($guidance, [
            'apply_field' => $canApply ? $applyField : null,
            'apply_value' => $canApply ? $applyValue : null,
            'can_apply' => $canApply,
            'apply_reason' => $applyReason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function resolveApplyReason(string $applyField, string $applyValue, array $normalized): string
    {
        if (! is_array($this->parsedSnapshot)) {
            if (str_starts_with($applyField, 'core.')) {
                $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];

                return trim((string) ($core[substr($applyField, 5)] ?? '')) !== ''
                    ? 'draft_not_in_parsed'
                    : 'detected_not_included';
            }

            return 'detected_not_included';
        }

        if ($applyField === '') {
            return 'detected_not_included';
        }

        if ($this->parsedSnapshotContainsApplyValue($this->parsedSnapshot, $applyField, $applyValue)) {
            return 'detected_not_included';
        }

        if (str_starts_with($applyField, 'core.')) {
            $core = is_array($this->parsedSnapshot['core'] ?? null) ? $this->parsedSnapshot['core'] : [];
            $existing = trim((string) ($core[substr($applyField, 5)] ?? ''));

            return $existing !== '' ? 'value_mismatch' : 'draft_not_in_parsed';
        }

        return 'draft_not_in_parsed';
    }

    private function applyHintForReason(?string $reason): ?string
    {
        return match ($reason) {
            'detected_not_included' => __('intake.normalized_draft_apply_reason_detected_not_included'),
            'value_mismatch' => __('intake.normalized_draft_apply_reason_value_mismatch'),
            'draft_not_in_parsed' => __('intake.normalized_draft_apply_reason_draft_not_in_parsed'),
            default => null,
        };
    }

    private function resolveApplyFieldPath(string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return '';
        }

        if (str_starts_with($field, 'core.')
            || str_starts_with($field, 'siblings.')
            || str_starts_with($field, 'relatives.')) {
            return $field;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $flag
     * @param  array<string, mixed>  $normalized
     * @param  array{missing_field: ?string, missing_value: ?string, correction_target: ?string}  $guidance
     */
    private function resolveApplyValue(array $flag, array $normalized, array $guidance, string $applyField): string
    {
        if ($applyField === 'core.other_relatives_text') {
            $sourceText = trim($this->preferredFlagRawLine(
                $this->stringify($flag['source_text'] ?? null),
                $this->stringify($flag['raw'] ?? null)
            ));
            $fromSource = $this->extractValueFromSourceLine($sourceText);
            if ($fromSource !== '') {
                return $fromSource;
            }
        }

        $fromNormalized = $this->normalizedValueForApplyPath($normalized, $applyField);
        if ($fromNormalized !== '' && $applyField !== 'core.other_relatives_text') {
            return $fromNormalized;
        }

        $fromGuidance = trim((string) ($guidance['missing_value'] ?? ''));
        if ($fromGuidance !== '') {
            return $fromGuidance;
        }

        $sourceText = trim($this->preferredFlagRawLine(
            $this->stringify($flag['source_text'] ?? null),
            $this->stringify($flag['raw'] ?? null)
        ));

        return $this->extractValueFromSourceLine($sourceText);
    }

    /**
     * @param  array<string, mixed>  $flag
     * @param  array<string, mixed>  $normalized
     */
    private function resolveMissingValueForFlag(array $flag, array $normalized, string $field): string
    {
        $applyField = $this->resolveApplyFieldPath($field);

        if ($applyField === 'core.other_relatives_text') {
            $sourceText = trim($this->preferredFlagRawLine(
                $this->stringify($flag['source_text'] ?? null),
                $this->stringify($flag['raw'] ?? null)
            ));
            $fromSource = $this->extractValueFromSourceLine($sourceText);
            if ($fromSource !== '') {
                return $fromSource;
            }
        }

        $fromNormalized = $this->normalizedValueForApplyPath($normalized, $applyField);
        if ($fromNormalized !== '' && $applyField !== 'core.other_relatives_text') {
            return $fromNormalized;
        }

        $sourceText = trim($this->preferredFlagRawLine(
            $this->stringify($flag['source_text'] ?? null),
            $this->stringify($flag['raw'] ?? null)
        ));

        return $this->extractValueFromSourceLine($sourceText);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function normalizedValueForApplyPath(array $normalized, string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return '';
        }

        if (str_starts_with($field, 'core.')) {
            $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
            $key = substr($field, 5);

            return trim((string) ($core[$key] ?? ''));
        }

        if (preg_match('/^(siblings|relatives)\.([a-z_]+)\.([a-z_]+)$/i', $field, $matches) !== 1) {
            return '';
        }

        [, $collection, $relationType, $property] = $matches;
        $rows = is_array($normalized[$collection] ?? null) ? $normalized[$collection] : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->canonicalRelationType((string) ($row['relation_type'] ?? '')) !== $this->canonicalRelationType($relationType)) {
                continue;
            }
            $value = trim((string) ($row[$property] ?? ''));
            if ($value !== '') {
                if ($property === 'name' && trim((string) ($row['contact_number'] ?? '')) !== '') {
                    return trim($value.' मो.'.($row['contact_number'] ?? ''));
                }

                return $value;
            }
        }

        return '';
    }

    private function canonicalRelationType(string $relationType): string
    {
        return mb_strtolower(trim(str_replace('-', '_', $relationType)));
    }

    private function isNegativeSiblingAssertion(string $applyField, string $applyValue): bool
    {
        if (! str_starts_with($applyField, 'siblings.')) {
            return false;
        }

        $value = mb_strtolower(trim($applyValue));
        if ($value === '') {
            return false;
        }

        foreach (['नाही', 'नाहीत', 'no', 'none', 'nil', 'na'] as $negative) {
            if ($value === $negative || str_starts_with($value, $negative.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function applyTargetNeedsCorrection(string $applyField, string $applyValue, array $normalized): bool
    {
        if (is_array($this->parsedSnapshot)) {
            return ! $this->parsedSnapshotContainsApplyValue($this->parsedSnapshot, $applyField, $applyValue);
        }

        if (str_starts_with($applyField, 'core.')) {
            return ! $this->coreFieldAlreadyHasValue($normalized, $applyField);
        }

        return trim($applyValue) !== '';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function parsedSnapshotContainsApplyValue(array $parsed, string $applyField, string $applyValue): bool
    {
        $applyValue = trim($applyValue);
        if ($applyValue === '') {
            return true;
        }

        if (str_starts_with($applyField, 'core.')) {
            $core = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];
            $key = substr($applyField, 5);
            $existing = trim((string) ($core[$key] ?? ''));
            if ($existing === '') {
                return false;
            }
            if ($key === 'other_relatives_text') {
                return str_contains($existing, $applyValue);
            }

            return $this->normalizedCompareText($existing) === $this->normalizedCompareText($applyValue);
        }

        if (preg_match('/^(siblings|relatives)\.([a-z_]+)\.([a-z_]+)$/i', $applyField, $matches) !== 1) {
            return false;
        }

        [, $collection, $relationType, $property] = $matches;
        $rows = is_array($parsed[$collection] ?? null) ? $parsed[$collection] : [];
        $needle = $this->normalizedCompareText($applyValue);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->canonicalRelationType((string) ($row['relation_type'] ?? '')) !== $this->canonicalRelationType($relationType)) {
                continue;
            }
            $candidate = trim((string) ($row[$property] ?? ''));
            if ($property === 'name') {
                [$name] = $this->splitNameAndPhoneForCompare($applyValue);
                $candidateNorm = $this->normalizedCompareText($candidate);
                if ($candidateNorm !== '' && ($candidateNorm === $this->normalizedCompareText($name) || str_contains($needle, $candidateNorm))) {
                    return true;
                }

                continue;
            }
            if ($candidate !== '' && $this->normalizedCompareText($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitNameAndPhoneForCompare(string $value): array
    {
        $phone = '';
        if (preg_match('/(?:मो\.?|mob\.?|mobile\.?|phone\.?)\s*([6-9]\d{9})/iu', $value, $matches) === 1) {
            $phone = OcrNormalize::normalizeDigits($matches[1]);
            $value = trim(preg_replace('/(?:मो\.?|mob\.?|mobile\.?|phone\.?)\s*[6-9]\d{9}/iu', '', $value) ?? $value);
        }

        return [trim($value), $phone];
    }

    private function normalizedCompareText(string $value): string
    {
        return mb_strtolower(trim(OcrNormalize::normalizeDigits($value)));
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function coreFieldAlreadyHasValue(array $normalized, string $field): bool
    {
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $key = str_starts_with($field, 'core.') ? substr($field, 5) : $field;

        return trim((string) ($core[$key] ?? '')) !== '';
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @param  list<array<string, mixed>>  $detectedRows
     * @return array<string, list<array<string, mixed>>>
     */
    private function enrichSectionsWithApplyActions(array $sections, array $detectedRows): array
    {
        foreach ($detectedRows as $row) {
            if (! is_array($row) || empty($row['can_apply']) || empty($row['apply_field']) || empty($row['apply_value'])) {
                continue;
            }

            $sectionKey = trim((string) ($row['target_section'] ?? ''));
            if ($sectionKey === '' || ! isset($sections[$sectionKey])) {
                continue;
            }

            $applyField = (string) $row['apply_field'];
            $applyValue = (string) $row['apply_value'];
            $applyReason = (string) ($row['apply_reason'] ?? 'draft_not_in_parsed');
            $applyHint = $this->applyHintForReason($applyReason);
            $attached = false;

            if ($applyField !== 'core.other_relatives_text') {
                foreach ($sections[$sectionKey] as $index => $sectionRow) {
                    if (! is_array($sectionRow) || (($sectionRow['field'] ?? null) !== $applyField)) {
                        continue;
                    }
                    if (! empty($sectionRow['can_apply'])) {
                        continue;
                    }
                    $sections[$sectionKey][$index]['can_apply'] = true;
                    $sections[$sectionKey][$index]['apply_field'] = $applyField;
                    $sections[$sectionKey][$index]['apply_value'] = $applyValue;
                    $sections[$sectionKey][$index]['apply_reason'] = $applyReason;
                    $sections[$sectionKey][$index]['correction_hint'] = $applyHint;
                    $attached = true;
                    break;
                }
            }

            if ($attached) {
                continue;
            }

            $sections[$sectionKey][] = [
                'label' => (string) ($row['missing_field'] ?? $this->reviewFieldLabel($applyField)),
                'value' => $applyValue,
                'field' => $applyField,
                'row_variant' => 'suggested_correction',
                'needs_review' => false,
                'can_apply' => true,
                'apply_field' => $applyField,
                'apply_value' => $applyValue,
                'apply_reason' => $applyReason,
                'source_line_no' => $row['source_line_no'] ?? null,
                'correction_hint' => $applyHint,
            ];
        }

        return $sections;
    }

    private function correctionTargetLabel(string $sectionLabel, string $fieldLabel): string
    {
        return trim($sectionLabel).' → '.trim($fieldLabel);
    }

    private function defaultSectionLabelForField(string $field): string
    {
        if (str_starts_with($field, 'core.')) {
            $coreField = substr($field, 5);
            if (in_array($coreField, self::EDUCATION_CAREER_FIELDS, true)) {
                return $this->reviewSectionLabel('education-career');
            }
            if (in_array($coreField, self::PHYSICAL_FIELDS, true)) {
                return $this->reviewSectionLabel('physical');
            }
            if (in_array($coreField, self::FAMILY_DETAIL_FIELDS, true)) {
                return $this->reviewSectionLabel('family-details');
            }

            return $this->reviewSectionLabel('basic-info');
        }

        if (str_starts_with($field, 'siblings.')) {
            return $this->reviewSectionLabel('siblings');
        }

        if (str_starts_with($field, 'relatives.') || str_starts_with($field, 'preferences.')) {
            return $this->reviewSectionLabel('alliance');
        }

        return '';
    }

    private function extractValueFromSourceLine(string $sourceText): string
    {
        $sourceText = trim($sourceText);
        if ($sourceText === '') {
            return '';
        }

        if (preg_match('/(\d+\s*कुळी|९६\s*कुळी)/u', $sourceText, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/:-\s*(.+)$/u', $sourceText, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/:\s*(.+)$/u', $sourceText, $matches)) {
            return trim($matches[1]);
        }

        return $sourceText;
    }

    /**
     * @param  array<string, mixed>  $flag
     * @param  array<string, mixed>  $normalized
     */
    private function coverageFlagIsFullyMapped(array $flag, array $normalized): bool
    {
        $field = (string) ($flag['field'] ?? '');
        if (! in_array($field, ['core.caste', 'core.sub_caste', 'core.religion'], true)) {
            return false;
        }

        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $religion = trim((string) ($core['religion'] ?? ''));
        $caste = trim((string) ($core['caste'] ?? ''));
        $subCaste = trim((string) ($core['sub_caste'] ?? ''));
        $sourceText = trim($this->preferredFlagRawLine(
            $this->stringify($flag['source_text'] ?? null),
            $this->stringify($flag['raw'] ?? null)
        ));

        if ($religion === '' || $caste === '') {
            return false;
        }

        if (preg_match('/(\d+\s*कुळी|९६\s*कुळी)/u', $sourceText)) {
            return $subCaste !== '';
        }

        if (preg_match('/हिंद[ुू]/u', $sourceText) && preg_match('/मराठा/u', $sourceText)) {
            return true;
        }

        return false;
    }

    private function draftSnapshotForCoverageField(string $field, array $normalized): ?string
    {
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        if ($field === 'core.caste' || $field === 'core.religion' || $field === 'core.sub_caste') {
            $parts = [];
            $religion = trim((string) ($core['religion'] ?? ''));
            $caste = trim((string) ($core['caste'] ?? ''));
            $subCaste = trim((string) ($core['sub_caste'] ?? ''));
            $parts[] = __('intake.normalized_draft_draft_religion', [
                'value' => $religion !== '' ? $religion : __('intake.normalized_draft_empty_field'),
            ]);
            $parts[] = __('intake.normalized_draft_draft_caste', [
                'value' => $caste !== '' ? $caste : __('intake.normalized_draft_empty_field'),
            ]);
            $parts[] = __('intake.normalized_draft_draft_sub_caste', [
                'value' => $subCaste !== '' ? $subCaste : __('intake.normalized_draft_empty_field'),
            ]);

            return implode(' · ', $parts);
        }

        if (in_array($field, ['core.occupation_title', 'core.company_name', 'core.work_location_text', 'core.highest_education'], true)) {
            $parts = [];
            $education = trim((string) ($core['highest_education'] ?? ''));
            $title = trim((string) ($core['occupation_title'] ?? ''));
            $company = trim((string) ($core['company_name'] ?? ''));
            $location = trim((string) ($core['work_location_text'] ?? ''));
            if ($education !== '') {
                $parts[] = __('intake.normalized_draft_draft_education', ['value' => $education]);
            }
            if ($title !== '') {
                $parts[] = __('intake.normalized_draft_draft_occupation', ['value' => $title]);
            }
            if ($company !== '') {
                $parts[] = __('intake.normalized_draft_draft_company', ['value' => $company]);
            }
            if ($location !== '') {
                $parts[] = __('intake.normalized_draft_draft_work_location', ['value' => $location]);
            }

            return $parts !== [] ? implode(' · ', $parts) : null;
        }

        $value = $this->normalizedCoreValueForField($field, $core);
        if ($value === null || $value === '') {
            return null;
        }

        return $this->reviewFieldLabel($field).' = '.$value;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function normalizedCoreValueForField(string $field, array $core): ?string
    {
        if (! str_starts_with($field, 'core.')) {
            return null;
        }
        $key = substr($field, 5);
        if ($key === '') {
            return null;
        }
        $value = $core[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return $this->formatFieldValue($key, (string) $value);
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

        return OcrNormalize::normalizeDigits($value);
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
            return OcrNormalize::normalizeDigits(trim((string) $value));
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

        if (! LocalizedText::isMarathi()) {
            return $value;
        }

        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            'married' => __('intake.normalized_draft_sibling_marital_married'),
            'unmarried', 'single', 'never_married' => __('intake.normalized_draft_sibling_marital_unmarried'),
            'divorced' => __('intake.normalized_draft_sibling_marital_divorced'),
            'widowed' => __('intake.normalized_draft_sibling_marital_widowed'),
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

        if (LocalizedText::isMarathi()) {
            $numbered = preg_match('/\s+\d+$/u', $normalizedPrefix) === 1;

            return match ($canonical) {
                'brother' => $numbered
                    ? __('intake.normalized_draft_marriage_info_numbered', ['prefix' => $normalizedPrefix])
                    : __('intake.normalized_draft_brother_marriage_info'),
                'sister' => $numbered
                    ? __('intake.normalized_draft_marriage_info_numbered', ['prefix' => $normalizedPrefix])
                    : __('intake.normalized_draft_sister_marriage_info'),
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
        return LocalizedText::isMarathi() && preg_match('/^[\p{Latin}\p{Common}\p{Zs}()\'".,&\-0-9]+$/u', $label) === 1;
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

        $spouse = is_array($sibling['spouse'] ?? null) ? $sibling['spouse'] : [];
        if ($spouse !== []) {
            $spouseLabel = $this->compactSpouseHeading($relationType);
            $spouseName = $this->stringify($spouse['name'] ?? null);
            $spouseOccurrence = preg_match('/\s+(\d+)$/u', trim($prefix), $m) === 1 ? (int) $m[1] : 1;
            $rawSpousePrefix = $this->localizedRelationDisplayLabel(
                'sibling',
                $this->siblingSpouseRelationFor($relationType),
                $spouseLabel
            );
            $spouseHeading = $spouseOccurrence > 1 ? $rawSpousePrefix.' '.$spouseOccurrence : $rawSpousePrefix;
            if ($spouseName !== '') {
                $rows[] = $this->groupHeadingRow($spouseHeading, $spouseName, true, null, $reviewMap);
            }
            foreach ([
                'occupation' => $this->relationFieldLabel('occupation'),
                'occupation_title' => $this->relationFieldLabel('occupation'),
                'address_line' => $this->relationFieldLabel('address_line'),
                'location_display' => $this->relationFieldLabel('location_display'),
                'contact_number' => $this->relationFieldLabel('contact_number'),
                'contact_number_2' => $this->indexedRelationFieldLabel('contact_number', 2),
                'contact_number_3' => $this->indexedRelationFieldLabel('contact_number', 3),
                'notes' => $this->relationFieldLabel('notes'),
                'additional_info' => $this->relationFieldLabel('notes'),
            ] as $field => $label) {
                $value = $this->stringify($spouse[$field] ?? null);
                if ($value === '') {
                    continue;
                }
                $rows[] = $this->groupDetailRow(
                    $spouseHeading.' '.$this->spouseRawFieldLabel($field),
                    $value,
                    null,
                    $reviewMap,
                    false,
                    ['display_label' => $label]
                );
            }
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
        return __('intake.normalized_draft_married_compact');
    }

    private function compactSpouseHeading(string $relationType): string
    {
        $spouseRelation = $this->siblingSpouseRelationFor($relationType);

        return $this->relativeRelationBaseLabel($spouseRelation);
    }

    private function familyHeading(string $type, string $name): string
    {
        $label = $type === 'father'
            ? __('intake.normalized_draft_father')
            : __('intake.normalized_draft_mother');

        return $label.' - '.$name;
    }

    private function familyAddressHeading(): string
    {
        return __('intake.normalized_draft_parents_address_heading');
    }

    private function familyDetailLabel(string $key): string
    {
        return match ($key) {
            'occupation' => __('intake.normalized_draft_family_detail_occupation'),
            'extra' => __('intake.normalized_draft_family_detail_extra'),
            'contact_1' => __('intake.normalized_draft_family_detail_contact', ['n' => 1]),
            'contact_2' => __('intake.normalized_draft_family_detail_contact', ['n' => 2]),
            'contact_3' => __('intake.normalized_draft_family_detail_contact', ['n' => 3]),
            default => $key,
        };
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function parentsAddressDetailLabel(array $address, int $index): string
    {
        $type = mb_strtolower(trim($this->stringify($address['type'] ?? null)));

        return match ($type) {
            'permanent' => __('intake.normalized_draft_parents_address_permanent'),
            'current' => __('intake.normalized_draft_parents_address_current'),
            'parents' => __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]),
            default => $type !== '' ? $this->localizedAddressType($type) : __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]),
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
