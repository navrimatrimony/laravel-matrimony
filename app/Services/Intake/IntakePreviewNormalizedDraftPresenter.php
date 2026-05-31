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
     *     sections: array<string, list<array{label: string, value: string}>>,
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

            return [
                'available' => true,
                'skipped_reason' => null,
                'build_error' => null,
                'sections' => [
                    'personal' => $this->personalRows($core),
                    'family' => $this->familyRows($core, $normalized),
                    'contacts' => $this->contactRows($core, $normalized),
                    'addresses' => $this->addressRows($core, $normalized),
                    'property' => $this->propertyRows($normalized),
                    'horoscope' => $this->horoscopeRows($normalized),
                    'relatives' => $this->relativeRows($core, $normalized),
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
     *     sections: array<string, list<array{label: string, value: string}>>,
     *     raw_draft_json: ?string
     * }
     */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'skipped_reason' => $reason,
            'build_error' => null,
            'sections' => $this->emptySections(),
            'raw_draft_json' => null,
        ];
    }

    /**
     * @return array<string, list<array{label: string, value: string}>>
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
     * @param  array<string, mixed>  $core
     * @return list<array{label: string, value: string}>
     */
    private function personalRows(array $core): array
    {
        $rows = [];
        foreach (self::PERSONAL_FIELDS as $field) {
            $value = $this->stringify($core[$field] ?? null);
            if ($value === '') {
                continue;
            }
            $rows[] = [
                'label' => $this->fieldLabel($field),
                'value' => $field === 'height_cm' ? $this->formatHeight($value) : $value,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @return list<array{label: string, value: string}>
     */
    private function familyRows(array $core, array $normalized): array
    {
        $rows = [];
        foreach (['father_name', 'father_occupation', 'father_extra_info', 'mother_name', 'mother_occupation'] as $field) {
            $value = $this->stringify($core[$field] ?? null);
            if ($value !== '') {
                $rows[] = ['label' => $this->fieldLabel($field), 'value' => $value];
            }
        }

        foreach (['brother_count', 'sister_count'] as $field) {
            if (! array_key_exists($field, $core) || $core[$field] === null || $core[$field] === '') {
                continue;
            }
            $rows[] = ['label' => $this->fieldLabel($field), 'value' => (string) $core[$field]];
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
            $rows[] = [
                'label' => __('intake.normalized_draft_sibling_row', ['n' => $index + 1]),
                'value' => implode(' · ', $parts),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @return list<array{label: string, value: string}>
     */
    private function contactRows(array $core, array $normalized): array
    {
        $rows = [];
        $primary = $this->stringify($core['primary_contact_number'] ?? null);
        if ($primary !== '') {
            $rows[] = ['label' => $this->fieldLabel('primary_contact_number'), 'value' => $primary];
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

            $rows[] = ['label' => $label, 'value' => $phone];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @return list<array{label: string, value: string}>
     */
    private function addressRows(array $core, array $normalized): array
    {
        $rows = [];
        $line = $this->stringify($core['address_line'] ?? null);
        if ($line !== '') {
            $rows[] = ['label' => $this->fieldLabel('address_line'), 'value' => $line];
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
            $rows[] = [
                'label' => $type !== ''
                    ? __('intake.normalized_draft_address_typed', ['type' => $type])
                    : __('intake.normalized_draft_address_row', ['n' => $index + 1]),
                'value' => $addrLine,
            ];
        }

        foreach ($normalized['parents_addresses'] ?? [] as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $addrLine = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($addrLine === '') {
                continue;
            }
            $rows[] = [
                'label' => __('intake.normalized_draft_parents_address_row', ['n' => $index + 1]),
                'value' => $addrLine,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return list<array{label: string, value: string}>
     */
    private function propertyRows(array $normalized): array
    {
        $property = $normalized['property_summary'] ?? null;
        if (! is_array($property)) {
            return [];
        }

        $rows = [];
        $summary = $this->stringify($property['summary_notes'] ?? $property['summary_text'] ?? null);
        if ($summary !== '') {
            $rows[] = ['label' => $this->fieldLabel('summary_notes'), 'value' => $summary];
        }

        foreach (['owns_house', 'owns_flat', 'owns_agriculture'] as $flag) {
            if (! empty($property[$flag])) {
                $rows[] = ['label' => $this->fieldLabel($flag), 'value' => 'yes'];
            }
        }

        if (isset($property['total_land_acres']) && $property['total_land_acres'] !== null && $property['total_land_acres'] !== '') {
            $rows[] = [
                'label' => $this->fieldLabel('total_land_acres'),
                'value' => (string) $property['total_land_acres'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return list<array{label: string, value: string}>
     */
    private function horoscopeRows(array $normalized): array
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
            $rows[] = [
                'label' => __('intake.normalized_draft_horoscope_line', ['n' => $index + 1]),
                'value' => $text,
            ];
        }

        foreach ($horoscope as $key => $value) {
            if ($key === 'raw' || ! is_scalar($value)) {
                continue;
            }
            $text = $this->stringify($value);
            if ($text === '') {
                continue;
            }
            $rows[] = ['label' => $this->fieldLabel((string) $key), 'value' => $text];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @return list<array{label: string, value: string}>
     */
    private function relativeRows(array $core, array $normalized): array
    {
        $rows = [];
        $other = $this->stringify($core['other_relatives_text'] ?? null);
        if ($other !== '') {
            $rows[] = ['label' => $this->fieldLabel('other_relatives_text'), 'value' => $other];
        }

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
            $rows[] = [
                'label' => __('intake.normalized_draft_relative_row', ['n' => $index + 1]),
                'value' => implode(' · ', array_unique($parts)),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $flags
     * @return list<array{label: string, value: string}>
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
            $rows[] = [
                'label' => $field !== '' ? $field : __('intake.normalized_draft_review_row', ['n' => $index + 1]),
                'value' => $valueParts !== [] ? implode(' — ', $valueParts) : '—',
            ];
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
            return $value ? 'true' : 'false';
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
