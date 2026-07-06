<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\MasterMotherTongue;
use App\Models\MatrimonyProfile;
use App\Models\OccupationCustom;
use App\Models\OccupationMaster;
use App\Services\Parsing\ParsedJsonSsotNormalizer;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BulkIntakeApplyPreviewService
{
    private const PREVIEW_FIELDS = [
        'core' => [
            'full_name',
            'gender_id',
            'date_of_birth',
            'marital_status_id',
            'religion_id',
            'caste_id',
            'sub_caste_id',
            'mother_tongue_id',
            'height_cm',
            'weight_kg',
            'highest_education',
            'occupation_master_id',
            'occupation_custom_id',
            'company_name',
            'work_location_text',
        ],
    ];

    private const FIELD_LABELS = [
        'full_name' => 'Full name',
        'gender_id' => 'Gender',
        'date_of_birth' => 'Date of birth',
        'marital_status_id' => 'Marital status',
        'religion_id' => 'Religion',
        'caste_id' => 'Caste',
        'sub_caste_id' => 'Sub caste',
        'mother_tongue_id' => 'Mother tongue',
        'height_cm' => 'Height',
        'weight_kg' => 'Weight',
        'highest_education' => 'Highest education',
        'occupation_master_id' => 'Occupation master',
        'occupation_custom_id' => 'Occupation custom',
        'company_name' => 'Company name',
        'work_location_text' => 'Work location',
    ];

    public function __construct(
        private readonly IntakePreviewFieldDisplayFormatter $displayFormatter
    ) {}

    /**
     * @return array{
     *     can_preview: bool,
     *     blocked_reasons: list<string>,
     *     profile_id: int|null,
     *     intake_id: int|null,
     *     groups: array<string, list<array{field: string, label: string, current_display: string, proposed_display: string, changed: bool, risk: 'safe'|'review'|'blocked', reason_codes: list<string>}>>,
     *     summary: array{total_fields: int, changed_fields: int, safe_count: int, review_count: int, blocked_count: int}
     * }
     */
    public function previewForItem(BulkIntakeBatchItem $item): array
    {
        $item->loadMissing([
            'biodataIntake:id,uploaded_by,matrimony_profile_id,parse_status,parsed_json,approved_by_user,intake_locked',
            'biodataIntake.profile',
        ]);

        $blockedReasons = [];
        $intake = $item->biodataIntake;
        $profile = $intake?->profile;

        if (! $intake instanceof BiodataIntake) {
            $blockedReasons[] = 'missing_linked_intake';
        }

        if ($intake instanceof BiodataIntake && $intake->matrimony_profile_id === null) {
            $blockedReasons[] = 'missing_linked_profile';
        }

        if ($intake instanceof BiodataIntake && $intake->matrimony_profile_id !== null && ! $profile instanceof MatrimonyProfile) {
            $blockedReasons[] = 'profile_missing';
        }

        if ($intake instanceof BiodataIntake && (string) $intake->parse_status !== 'parsed') {
            $blockedReasons[] = 'intake_not_parsed';
        }

        if ($intake instanceof BiodataIntake && (! is_array($intake->parsed_json) || $intake->parsed_json === [])) {
            $blockedReasons[] = 'missing_parsed_json';
        }

        if ($profile instanceof MatrimonyProfile && (string) $profile->lifecycle_state !== 'draft') {
            $blockedReasons[] = 'profile_not_draft';
        }

        if (! $this->isSafeLinkedDraftItemStatus((string) $item->item_status)) {
            $blockedReasons[] = 'item_not_safe_for_preview';
        }

        if ($blockedReasons !== [] || ! $intake instanceof BiodataIntake || ! $profile instanceof MatrimonyProfile) {
            return $this->result(false, $blockedReasons, $intake, $profile, []);
        }

        $parsed = ParsedJsonSsotNormalizer::normalize($intake->parsed_json);
        $core = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];
        $groups = [];

        foreach (self::PREVIEW_FIELDS['core'] as $field) {
            if (! array_key_exists($field, $core) || $this->isEmptyValue($core[$field])) {
                continue;
            }

            $groups['Core'][] = $this->fieldRow($field, $profile->getAttribute($field), $core[$field], $core);
        }

        foreach ($core as $field => $value) {
            if (! is_string($field) || in_array($field, self::PREVIEW_FIELDS['core'], true) || $this->isEmptyValue($value)) {
                continue;
            }

            $groups['Unsupported'][] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? Str::headline($field),
                'current_display' => '-',
                'proposed_display' => $this->displayScalar($value),
                'changed' => true,
                'risk' => 'review',
                'reason_codes' => ['unsupported_field'],
            ];
        }

        if ($groups === []) {
            return $this->result(false, ['missing_parsed_json'], $intake, $profile, []);
        }

        return $this->result(true, [], $intake, $profile, $groups);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{field: string, label: string, current_display: string, proposed_display: string, changed: bool, risk: 'safe'|'review'|'blocked', reason_codes: list<string>}
     */
    private function fieldRow(string $field, mixed $current, mixed $proposed, array $context): array
    {
        $reasonCodes = [];
        $risk = 'safe';

        if (! Schema::hasColumn('matrimony_profiles', $field)) {
            $risk = 'blocked';
            $reasonCodes[] = 'unsupported_field';
        }

        if ($risk !== 'blocked' && $this->isIdField($field) && (! is_numeric($proposed) || (int) $proposed < 1)) {
            $risk = 'review';
            $reasonCodes[] = 'invalid_reference_value';
        }

        $changed = ! $this->sameValue($current, $proposed);
        if ($changed && ! $this->isEmptyValue($current) && $risk === 'safe') {
            $risk = 'review';
            $reasonCodes[] = 'would_overwrite_existing_value';
        }

        if ($reasonCodes === []) {
            $reasonCodes[] = $changed ? 'parsed_value_available' : 'same_as_current';
        }

        return [
            'field' => $field,
            'label' => self::FIELD_LABELS[$field] ?? Str::headline($field),
            'current_display' => $this->displayForField($field, $current, $context),
            'proposed_display' => $this->displayForField($field, $proposed, $context),
            'changed' => $changed,
            'risk' => $risk,
            'reason_codes' => array_values(array_unique($reasonCodes)),
        ];
    }

    /**
     * @param  array<string, list<array{field: string, label: string, current_display: string, proposed_display: string, changed: bool, risk: 'safe'|'review'|'blocked', reason_codes: list<string>}>>  $groups
     * @return array{
     *     can_preview: bool,
     *     blocked_reasons: list<string>,
     *     profile_id: int|null,
     *     intake_id: int|null,
     *     groups: array<string, list<array{field: string, label: string, current_display: string, proposed_display: string, changed: bool, risk: 'safe'|'review'|'blocked', reason_codes: list<string>}>>,
     *     summary: array{total_fields: int, changed_fields: int, safe_count: int, review_count: int, blocked_count: int}
     * }
     */
    private function result(bool $canPreview, array $blockedReasons, ?BiodataIntake $intake, ?MatrimonyProfile $profile, array $groups): array
    {
        $summary = [
            'total_fields' => 0,
            'changed_fields' => 0,
            'safe_count' => 0,
            'review_count' => 0,
            'blocked_count' => 0,
        ];

        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                $summary['total_fields']++;
                if ($row['changed']) {
                    $summary['changed_fields']++;
                }
                $summary[$row['risk'].'_count']++;
            }
        }

        return [
            'can_preview' => $canPreview,
            'blocked_reasons' => array_values(array_unique($blockedReasons)),
            'profile_id' => $profile?->id,
            'intake_id' => $intake?->id,
            'groups' => $groups,
            'summary' => $summary,
        ];
    }

    private function displayForField(string $field, mixed $value, array $context): string
    {
        if ($this->isEmptyValue($value)) {
            return '-';
        }

        $display = match ($field) {
            'mother_tongue_id' => $this->motherTongueLabel($value),
            'occupation_master_id' => $this->occupationMasterLabel($value),
            'occupation_custom_id' => $this->occupationCustomLabel($value),
            default => $this->displayFormatter->format($field, $value, $context),
        };

        return $display !== '' ? $display : $this->displayScalar($value);
    }

    private function displayScalar(mixed $value): string
    {
        if ($this->isEmptyValue($value)) {
            return '-';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) && $encoded !== '' ? $encoded : '-';
    }

    private function motherTongueLabel(mixed $value): string
    {
        $id = is_numeric($value) ? (int) $value : 0;
        $row = $id > 0 ? MasterMotherTongue::query()->find($id) : null;

        return $row ? (string) ($row->label ?? $row->key ?? $id) : $this->displayScalar($value);
    }

    private function occupationMasterLabel(mixed $value): string
    {
        $id = is_numeric($value) ? (int) $value : 0;
        $row = $id > 0 ? OccupationMaster::query()->find($id) : null;

        return $row ? (string) ($row->name_mr ?? $row->name ?? $id) : $this->displayScalar($value);
    }

    private function occupationCustomLabel(mixed $value): string
    {
        $id = is_numeric($value) ? (int) $value : 0;
        $row = $id > 0 ? OccupationCustom::query()->find($id) : null;

        return $row ? (string) ($row->raw_name ?? $row->normalized_name ?? $id) : $this->displayScalar($value);
    }

    private function isIdField(string $field): bool
    {
        return str_ends_with($field, '_id');
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }

    private function sameValue(mixed $current, mixed $proposed): bool
    {
        if ($current instanceof \DateTimeInterface) {
            $current = $current->format('Y-m-d');
        }

        if ($proposed instanceof \DateTimeInterface) {
            $proposed = $proposed->format('Y-m-d');
        }

        if ($this->isEmptyValue($current) && $this->isEmptyValue($proposed)) {
            return true;
        }

        return $this->compareValue($current) === $this->compareValue($proposed);
    }

    private function compareValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    private function isSafeLinkedDraftItemStatus(string $status): bool
    {
        return ! in_array($status, [
            BulkIntakeBatchItem::STATUS_FAILED,
            BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
            BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
        ], true);
    }
}
