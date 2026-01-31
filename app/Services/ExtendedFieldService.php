<?php

namespace App\Services;

use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;
use App\Models\ProfileExtendedField;
use App\Services\ProfileFieldLockService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class ExtendedFieldService
{
    public static function getValuesForProfile(MatrimonyProfile $profile): array
    {
        $rows = ProfileExtendedField::where('profile_id', $profile->id)->get();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->field_key] = $row->field_value;
        }
        return $out;
    }

    /**
     * Returns field keys whose value actually changed (Day-6.2: intent detection).
     */
    public static function getChangedExtendedFieldKeys(MatrimonyProfile $profile, array $input): array
    {
        $existing = static::getValuesForProfile($profile);
        $changed = [];
        foreach ($input as $field_key => $value) {
            $registry = FieldRegistry::where('field_key', $field_key)->where('field_type', 'EXTENDED')->first();
            if (!$registry) {
                continue;
            }
            $normalized = static::normalizeValue($registry, $value);
            $oldVal = $existing[$field_key] ?? null;
            $oldVal = $oldVal === '' ? null : $oldVal;
            $normalized = $normalized === '' ? null : $normalized;
            if ((string) $normalized !== (string) $oldVal) {
                $changed[] = $field_key;
            }
        }
        return $changed;
    }

    public static function saveValuesForProfile(MatrimonyProfile $profile, array $input, ?\App\Models\User $actor = null): void
    {
        $changedKeys = static::getChangedExtendedFieldKeys($profile, $input);
        // Day-6: Overwrite protection - authority-aware (equal/higher can edit locked)
        ProfileFieldLockService::assertNotLocked($profile, $changedKeys, $actor);

        foreach ($input as $field_key => $value) {
            $registry = FieldRegistry::where('field_key', $field_key)
                ->where('field_type', 'EXTENDED')
                ->first();

            if (!$registry) {
                throw ValidationException::withMessages([
                    $field_key => ['Extended field is not defined in registry.'],
                ]);
            }

            if (!static::validateValue($registry, $value)) {
                throw ValidationException::withMessages([
                    $field_key => ['Invalid value for data type ' . $registry->data_type . '.'],
                ]);
            }

            $normalized = static::normalizeValue($registry, $value);
            $row = ProfileExtendedField::firstOrNew([
                'profile_id' => $profile->id,
                'field_key'  => $field_key,
            ]);
            $row->field_value = $normalized;
            $row->save();
        }
    }

    public static function validateValue(FieldRegistry $field, $value): bool
    {
        if ($value === null || $value === '') {
            return in_array($field->data_type, ['text', 'number', 'date', 'boolean', 'select'], true);
        }

        return match ($field->data_type) {
            'text'    => true,
            'number'  => is_numeric($value),
            'date'    => static::isParseableDate($value),
            'boolean' => static::isValidBoolean($value),
            'select'  => is_string($value) || $value === null,
            default   => false,
        };
    }

    protected static function isParseableDate($value): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }
        try {
            Carbon::parse($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected static function isValidBoolean($value): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', 'false', '0', '1'], true);
        }
        return false;
    }

    protected static function normalizeValue(FieldRegistry $field, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($field->data_type === 'boolean') {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_string($value) && strtolower($value) === 'true') {
                return '1';
            }
            if (is_string($value) && strtolower($value) === 'false') {
                return '0';
            }
            return (string) (int) (bool) $value;
        }
        return (string) $value;
    }
}
