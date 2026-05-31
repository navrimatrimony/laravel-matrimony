<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use Throwable;

/**
 * Read-only preview view-model for normalized biodata draft (not persisted).
 */
final class IntakePreviewNormalizedDraftPresenter
{
    /** @var list<string> */
    private const PERSONAL_FIELDS = [
        'full_name', 'gender', 'date_of_birth', 'birth_time', 'birth_place_text',
        'religion', 'caste', 'sub_caste', 'height_cm', 'complexion', 'blood_group',
        'marital_status', 'highest_education', 'occupation_title', 'company_name',
        'annual_income', 'work_location_text',
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

            return [
                'available' => true,
                'skipped_reason' => null,
                'build_error' => null,
                'review_flags_by_field' => $reviewMap,
                'sections' => [
                    'personal' => $this->personalRows($core, $reviewMap),
                    'family' => $this->familyRows($core, $normalized, $reviewMap),
                    'contacts' => $this->contactRows($core, $normalized, $reviewMap),
                    'addresses' => $this->addressRows($core, $normalized, $reviewMap),
                    'property' => $this->propertyRows($normalized, $reviewMap),
                    'horoscope' => $this->horoscopeRows($normalized, $reviewMap),
                    'relatives' => $this->relativeRows($core, $normalized, $reviewMap),
                    'review_needed' => $this->reviewRows($flags),
                ],
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
        return [
            'personal' => [],
            'family' => [],
            'contacts' => [],
            'addresses' => [],
            'property' => [],
            'horoscope' => [],
            'relatives' => [],
            'review_needed' => [],
        ];
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
        }

        return $map;
    }

    /**
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}
     */
    private function displayRow(string $label, string $value, ?string $reviewFieldKey, array $reviewMap): array
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

        return [
            'label' => $label,
            'value' => $value,
            'field' => $reviewFieldKey,
            'needs_review' => $needsReview,
            'review_reason' => $reviewReason !== '' ? $reviewReason : null,
            'review_hint' => $reviewHint,
        ];
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function personalRows(array $core, array $reviewMap): array
    {
        $rows = [];
        foreach (self::PERSONAL_FIELDS as $field) {
            $value = $this->stringify($core[$field] ?? null);
            if ($value === '') {
                continue;
            }
            $rows[] = $this->displayRow(
                $this->fieldLabel($field),
                $field === 'height_cm' ? $this->formatHeight($value) : $value,
                'core.'.$field,
                $reviewMap
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function familyRows(array $core, array $normalized, array $reviewMap): array
    {
        $rows = [];
        foreach (['father_name', 'father_occupation', 'father_extra_info', 'mother_name', 'mother_occupation'] as $field) {
            $value = $this->stringify($core[$field] ?? null);
            if ($value !== '') {
                $rows[] = $this->displayRow($this->fieldLabel($field), $value, 'core.'.$field, $reviewMap);
            }
        }

        foreach (['brother_count', 'sister_count'] as $field) {
            if (! array_key_exists($field, $core) || $core[$field] === null || $core[$field] === '') {
                continue;
            }
            $rows[] = $this->displayRow($this->fieldLabel($field), (string) $core[$field], 'core.'.$field, $reviewMap);
        }

        foreach ($normalized['siblings'] ?? [] as $index => $sibling) {
            if (! is_array($sibling)) {
                continue;
            }
            $parts = array_filter([
                $this->stringify($sibling['relation_type'] ?? null),
                $this->stringify($sibling['name'] ?? null),
                $this->stringify($sibling['marital_status'] ?? null),
                $this->stringify($sibling['occupation'] ?? null),
                $this->stringify($sibling['address_line'] ?? null),
            ]);
            if ($parts === []) {
                continue;
            }
            $rows[] = $this->displayRow(
                __('intake.normalized_draft_sibling_row', ['n' => $index + 1]),
                implode(' · ', $parts),
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
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function contactRows(array $core, array $normalized, array $reviewMap): array
    {
        $rows = [];
        $primary = $this->stringify($core['primary_contact_number'] ?? null);
        if ($primary !== '') {
            $rows[] = $this->displayRow(
                $this->fieldLabel('primary_contact_number'),
                $primary,
                'core.primary_contact_number',
                $reviewMap
            );
        }

        foreach ($normalized['contacts'] ?? [] as $index => $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = $this->stringify($contact['phone_number'] ?? $contact['number'] ?? null);
            if ($phone === '') {
                continue;
            }
            $labelParts = array_filter([
                $this->stringify($contact['label'] ?? null),
                $this->stringify($contact['contact_name'] ?? null),
                $this->stringify($contact['relation_type'] ?? null),
            ]);
            $label = $labelParts !== []
                ? implode(' / ', $labelParts)
                : __('intake.normalized_draft_contact_row', ['n' => $index + 1]);

            $rows[] = $this->displayRow($label, $phone, null, $reviewMap);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function addressRows(array $core, array $normalized, array $reviewMap): array
    {
        $rows = [];
        $line = $this->stringify($core['address_line'] ?? null);
        if ($line !== '') {
            $rows[] = $this->displayRow($this->fieldLabel('address_line'), $line, 'core.address_line', $reviewMap);
        }

        foreach ($normalized['addresses'] ?? [] as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $addrLine = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($addrLine === '') {
                continue;
            }
            $type = $this->stringify($address['type'] ?? null);
            $rows[] = $this->displayRow(
                $type !== ''
                    ? __('intake.normalized_draft_address_typed', ['type' => $type])
                    : __('intake.normalized_draft_address_row', ['n' => $index + 1]),
                $addrLine,
                null,
                $reviewMap
            );
        }

        foreach ($normalized['parents_addresses'] ?? [] as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $addrLine = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($addrLine === '') {
                continue;
            }
            $rows[] = $this->displayRow(
                __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]),
                $addrLine,
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
    private function propertyRows(array $normalized, array $reviewMap): array
    {
        $property = $normalized['property_summary'] ?? null;
        if (! is_array($property)) {
            return [];
        }

        $rows = [];
        $summary = $this->stringify($property['summary_notes'] ?? $property['summary_text'] ?? null);
        if ($summary !== '') {
            $rows[] = $this->displayRow($this->fieldLabel('summary_notes'), $summary, null, $reviewMap);
        }

        foreach (['owns_house', 'owns_flat', 'owns_agriculture'] as $flag) {
            if (! empty($property[$flag])) {
                $rows[] = $this->displayRow($this->fieldLabel($flag), 'होय', null, $reviewMap);
            }
        }

        if (isset($property['total_land_acres']) && $property['total_land_acres'] !== null && $property['total_land_acres'] !== '') {
            $rows[] = $this->displayRow(
                $this->fieldLabel('total_land_acres'),
                (string) $property['total_land_acres'],
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
    private function horoscopeRows(array $normalized, array $reviewMap): array
    {
        $horoscope = $normalized['horoscope'] ?? null;
        if (! is_array($horoscope)) {
            return [];
        }

        $rows = [];
        $rawLines = is_array($horoscope['raw'] ?? null) ? $horoscope['raw'] : [];
        foreach ($rawLines as $index => $line) {
            $text = $this->stringify($line);
            if ($text === '') {
                continue;
            }
            $rows[] = $this->displayRow(
                __('intake.normalized_draft_horoscope_line', ['n' => $index + 1]),
                $text,
                null,
                $reviewMap
            );
        }

        foreach ($horoscope as $key => $value) {
            if ($key === 'raw' || ! is_scalar($value)) {
                continue;
            }
            $text = $this->stringify($value);
            if ($text === '') {
                continue;
            }
            $rows[] = $this->displayRow($this->fieldLabel((string) $key), $text, null, $reviewMap);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @param  array<string, list<array{reason: string, raw: string}>>  $reviewMap
     * @return list<array{label: string, value: string, field: ?string, needs_review: bool, review_reason: ?string, review_hint: ?string}>
     */
    private function relativeRows(array $core, array $normalized, array $reviewMap): array
    {
        $rows = [];
        $other = $this->stringify($core['other_relatives_text'] ?? null);
        if ($other !== '') {
            $rows[] = $this->displayRow(
                $this->fieldLabel('other_relatives_text'),
                $other,
                'core.other_relatives_text',
                $reviewMap
            );
        }

        $relativesFlagged = isset($reviewMap['relatives']);
        foreach ($normalized['relatives'] ?? [] as $index => $relative) {
            if (! is_array($relative)) {
                continue;
            }
            $parts = array_filter([
                $this->stringify($relative['name'] ?? null),
                $this->stringify($relative['raw'] ?? null),
            ]);
            if ($parts === []) {
                continue;
            }
            $rows[] = $this->displayRow(
                __('intake.normalized_draft_relative_row', ['n' => $index + 1]),
                implode(' · ', array_unique($parts)),
                $relativesFlagged ? 'relatives' : null,
                $reviewMap
            );
        }

        return $rows;
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
            if ($field === '' && $reason === '' && $raw === '') {
                continue;
            }
            $valueParts = array_filter([$reason, $raw]);
            $row = $this->displayRow(
                $field !== '' ? $field : __('intake.normalized_draft_review_row', ['n' => $index + 1]),
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
        $translated = __('profile.'.$field);
        if ($translated !== 'profile.'.$field) {
            return $translated;
        }

        return ucfirst(str_replace('_', ' ', $field));
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

        return $cm.' cm';
    }
}
