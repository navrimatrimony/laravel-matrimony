<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use InvalidArgumentException;

/**
 * Applies admin-confirmed draft corrections into intake parsed_json (SSOT for approval/apply).
 */
final class IntakeNormalizedDraftCorrectionApplier
{
    /** @var list<string> */
    private const ALLOWED_CORE_FIELDS = [
        'full_name', 'gender', 'date_of_birth', 'birth_time', 'birth_place_text',
        'religion', 'caste', 'sub_caste', 'marital_status', 'address_line',
        'height_cm', 'complexion', 'blood_group', 'weight_kg',
        'highest_education', 'occupation_title', 'company_name', 'annual_income',
        'work_location_text', 'specialization',
        'father_name', 'father_occupation', 'mother_name', 'mother_occupation',
        'primary_contact_number', 'other_relatives_text',
        'brothers_count', 'sisters_count', 'brother_count', 'sister_count', 'has_siblings',
    ];

    public function __construct(
        private readonly IntakeParsedSnapshotSkeleton $snapshotSkeleton,
    ) {}

    public static function supportsField(string $field): bool
    {
        $field = trim($field);
        if ($field === '') {
            return false;
        }

        if (str_starts_with($field, 'core.')) {
            $key = substr($field, 5);

            return in_array($key, self::ALLOWED_CORE_FIELDS, true);
        }

        if (preg_match('/^siblings\.([a-z_]+)\.([a-z_]+)$/i', $field, $matches) === 1) {
            return in_array($matches[2], ['name', 'occupation', 'address_line', 'contact_number', 'marital_status', 'notes'], true);
        }

        if (preg_match('/^relatives\.([a-z_]+)\.([a-z_]+)$/i', $field, $matches) === 1) {
            return in_array($matches[2], ['name', 'occupation', 'address_line', 'contact_number', 'notes'], true);
        }

        return false;
    }

    /**
     * @return array{ok: bool, field: string, value: string, message: string}
     */
    public function apply(BiodataIntake $intake, string $field, string $value): array
    {
        if ($intake->approved_by_user || $intake->intake_locked) {
            throw new InvalidArgumentException(__('intake.normalized_draft_apply_locked'));
        }

        $field = trim($field);
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(__('intake.normalized_draft_apply_empty_value'));
        }

        if (! self::supportsField($field)) {
            throw new InvalidArgumentException(__('intake.normalized_draft_apply_invalid_field'));
        }

        $parsed = is_array($intake->parsed_json) ? $intake->parsed_json : [];

        if (str_starts_with($field, 'core.')) {
            $parsed = $this->applyCoreField($parsed, $field, $value);
        } elseif (str_starts_with($field, 'siblings.')) {
            $parsed = $this->applySiblingField($parsed, $field, $value);
        } elseif (str_starts_with($field, 'relatives.')) {
            $parsed = $this->applyRelativeField($parsed, $field, $value);
        }

        $parsed = $this->snapshotSkeleton->ensure($parsed);
        $intake->parsed_json = $parsed;
        $intake->save();

        return [
            'ok' => true,
            'field' => $field,
            'value' => $value,
            'message' => __('intake.normalized_draft_apply_success', [
                'field' => $field,
                'value' => $value,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function applyCoreField(array $parsed, string $field, string $value): array
    {
        $coreKey = $this->normalizeCoreFieldKey($field);
        if ($coreKey === null) {
            throw new InvalidArgumentException(__('intake.normalized_draft_apply_invalid_field'));
        }

        $core = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];

        if ($coreKey === 'other_relatives_text') {
            $existing = trim((string) ($core[$coreKey] ?? ''));
            if ($existing === '') {
                $core[$coreKey] = $value;
            } elseif (! str_contains($existing, $value)) {
                $core[$coreKey] = rtrim($existing, " ;\n\r\t").'; '.$value;
            }
        } else {
            $core[$coreKey] = $value;
        }

        $parsed['core'] = $core;

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function applySiblingField(array $parsed, string $field, string $value): array
    {
        if (preg_match('/^siblings\.([a-z_]+)\.([a-z_]+)$/i', $field, $matches) !== 1) {
            throw new InvalidArgumentException(__('intake.normalized_draft_apply_invalid_field'));
        }

        [, $relationType, $property] = $matches;
        $siblings = is_array($parsed['siblings'] ?? null) ? $parsed['siblings'] : [];
        $row = $this->findRelationRow($siblings, (string) $relationType, $value, $property === 'name');
        if ($row === null) {
            $row = $this->snapshotSkeleton->siblingRowDefaults();
            $row['relation_type'] = (string) $relationType;
            $siblings[] = $row;
            $rowIndex = array_key_last($siblings);
        } else {
            $rowIndex = $row['index'];
        }

        if (! is_int($rowIndex)) {
            throw new InvalidArgumentException(__('intake.normalized_draft_apply_invalid_field'));
        }

        $siblings[$rowIndex] = $this->assignRelationProperty(
            is_array($siblings[$rowIndex] ?? null) ? $siblings[$rowIndex] : $this->snapshotSkeleton->siblingRowDefaults(),
            (string) $property,
            $value
        );
        $parsed['siblings'] = array_values($siblings);

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function applyRelativeField(array $parsed, string $field, string $value): array
    {
        if (preg_match('/^relatives\.([a-z_]+)\.([a-z_]+)$/i', $field, $matches) !== 1) {
            throw new InvalidArgumentException(__('intake.normalized_draft_apply_invalid_field'));
        }

        [, $relationType, $property] = $matches;
        $relatives = is_array($parsed['relatives'] ?? null) ? $parsed['relatives'] : [];
        $row = $this->findRelationRow($relatives, (string) $relationType, $value, $property === 'name');
        if ($row === null) {
            $row = $this->snapshotSkeleton->relativeRowDefaults();
            $row['relation_type'] = (string) $relationType;
            $relatives[] = $row;
            $rowIndex = array_key_last($relatives);
        } else {
            $rowIndex = $row['index'];
        }

        if (! is_int($rowIndex)) {
            throw new InvalidArgumentException(__('intake.normalized_draft_apply_invalid_field'));
        }

        $relatives[$rowIndex] = $this->assignRelationProperty(
            is_array($relatives[$rowIndex] ?? null) ? $relatives[$rowIndex] : $this->snapshotSkeleton->relativeRowDefaults(),
            (string) $property,
            $value
        );
        $parsed['relatives'] = array_values($relatives);

        return $parsed;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{index: int}|null
     */
    private function findRelationRow(array $rows, string $relationType, string $value, bool $matchByName): ?array
    {
        $normalizedValue = $this->normalizedCompareText($value);
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->normalizedCompareText((string) ($row['relation_type'] ?? '')) !== $this->normalizedCompareText($relationType)) {
                continue;
            }
            if (! $matchByName) {
                return ['index' => $index];
            }
            $name = $this->normalizedCompareText((string) ($row['name'] ?? ''));
            if ($name !== '' && ($name === $normalizedValue || str_contains($normalizedValue, $name) || str_contains($name, $normalizedValue))) {
                return ['index' => $index];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function assignRelationProperty(array $row, string $property, string $value): array
    {
        if ($property === 'name') {
            [$name, $phone] = $this->splitNameAndPhone($value);
            $row['name'] = $name !== '' ? $name : $value;
            if ($phone !== '' && trim((string) ($row['contact_number'] ?? '')) === '') {
                $row['contact_number'] = $phone;
            }

            return $row;
        }

        $row[$property] = $value;

        return $row;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitNameAndPhone(string $value): array
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

    private function normalizeCoreFieldKey(string $field): ?string
    {
        $field = trim($field);
        if (str_starts_with($field, 'core.')) {
            $field = substr($field, 5);
        }
        if ($field === '' || ! in_array($field, self::ALLOWED_CORE_FIELDS, true)) {
            return null;
        }

        return $field;
    }
}
