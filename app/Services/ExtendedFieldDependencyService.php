<?php

namespace App\Services;

use App\Models\FieldRegistry;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Phase-3 Day 10: EXTENDED field dependency — DISPLAY/VISIBILITY ONLY.
 * No validation, completeness, search, or storage logic.
 */
class ExtendedFieldDependencyService
{
    /**
     * Filter EXTENDED fields to those visible given current extended values (UI only).
     * No parent => visible. Parent + condition => visible if condition met.
     */
    public static function filterVisibleForDisplay(Collection $fields, array $extendedValues): Collection
    {
        return $fields->filter(function (FieldRegistry $field) use ($extendedValues) {
            $parentKey = $field->parent_field_key ?? null;
            if ($parentKey === null || $parentKey === '') {
                return true;
            }
            $parentValue = $extendedValues[$parentKey] ?? null;
            $parentValue = $parentValue === '' ? null : (string) $parentValue;
            $condition = $field->dependency_condition;
            if (! is_array($condition) || empty($condition['type'])) {
                return true;
            }
            if ($condition['type'] === 'present') {
                return $parentValue !== null && $parentValue !== '';
            }
            if ($condition['type'] === 'equals') {
                $expected = $condition['value'] ?? null;
                $expected = $expected === '' ? null : (string) $expected;
                return $parentValue === $expected;
            }
            return true;
        });
    }

    /**
     * Validate dependency for one EXTENDED field. Throws ValidationException on violation.
     * Reject: CORE parent, self-parent, circular, nested (parent has parent), multi-parent (schema allows one only).
     */
    public static function validateDependency(
        FieldRegistry $field,
        ?string $parentFieldKey,
        ?array $condition,
        string $messagePrefix = 'Dependency '
    ): void {
        if ($parentFieldKey === null || $parentFieldKey === '') {
            return;
        }
        if ($field->field_key === $parentFieldKey) {
            throw ValidationException::withMessages([
                'parent_field_key' => [$messagePrefix . 'parent cannot be the field itself.'],
            ]);
        }
        $parent = FieldRegistry::where('field_key', $parentFieldKey)->first();
        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_field_key' => [$messagePrefix . 'parent field does not exist.'],
            ]);
        }
        if ($parent->field_type !== 'EXTENDED') {
            throw ValidationException::withMessages([
                'parent_field_key' => [$messagePrefix . 'parent must be an EXTENDED field, not CORE.'],
            ]);
        }
        if ($parent->parent_field_key !== null && $parent->parent_field_key !== '') {
            throw ValidationException::withMessages([
                'parent_field_key' => [$messagePrefix . 'nested dependencies are not allowed (parent must not have a parent).'],
            ]);
        }
        $visited = [$field->field_key => true];
        $current = $parent;
        while ($current) {
            if (isset($visited[$current->field_key])) {
                throw ValidationException::withMessages([
                    'parent_field_key' => [$messagePrefix . 'circular dependency detected.'],
                ]);
            }
            $visited[$current->field_key] = true;
            $nextKey = $current->parent_field_key ?? null;
            $current = $nextKey ? FieldRegistry::where('field_key', $nextKey)->first() : null;
        }
    }

    /**
     * Build dependency_condition array from type and value (for storage).
     */
    public static function buildCondition(string $type, ?string $value): array
    {
        if ($type === 'present') {
            return ['type' => 'present'];
        }
        if ($type === 'equals') {
            return ['type' => 'equals', 'value' => $value ?? ''];
        }
        return [];
    }
}
