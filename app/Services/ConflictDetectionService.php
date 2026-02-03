<?php

namespace App\Services;

use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;

/**
 * Phase-3 Day-13: Manual conflict detection.
 * Compares profile current values vs proposed data; creates ConflictRecord for mismatches.
 * Skips locked fields. Does NOT mutate profile data.
 */
class ConflictDetectionService
{
    /** CORE field keys compared for conflict (schema-bound, editable). */
    private const CORE_FIELD_KEYS = [
        'full_name',
        'gender',
        'date_of_birth',
        'marital_status',
        'education',
        'location',
        'caste',
        'height_cm',
        'profile_photo',
    ];

    /**
     * Run conflict detection: compare profile vs proposed data, create ConflictRecords for mismatches.
     * Skips locked fields. Does not update any profile field.
     *
     * @param  array<string, mixed>  $proposedCore  Optional. Keys = field_key, value = proposed value (string|null).
     * @param  array<string, mixed>  $proposedExtended  Optional. Keys = field_key, value = proposed value (string|null).
     * @return ConflictRecord[] Created conflict records (may be multiple per profile).
     */
    public static function detect(MatrimonyProfile $profile, array $proposedCore = [], array $proposedExtended = []): array
    {
        $created = [];

        // CORE: compare each proposed value to current profile value
        foreach (self::CORE_FIELD_KEYS as $fieldKey) {
            if (!array_key_exists($fieldKey, $proposedCore)) {
                continue;
            }
            if (ProfileFieldLockService::isLocked($profile, $fieldKey)) {
                continue;
            }
            $current = self::getCurrentCoreValue($profile, $fieldKey);
            $proposed = self::normalize($proposedCore[$fieldKey]);
            if (self::valuesDiffer($current, $proposed)) {
                $created[] = ConflictRecord::create([
                    'profile_id' => $profile->id,
                    'field_name' => $fieldKey,
                    'field_type' => 'CORE',
                    'old_value' => $current === null ? null : (string) $current,
                    'new_value' => $proposed === null ? null : (string) $proposed,
                    'source' => 'SYSTEM',
                    'detected_at' => now(),
                    'resolution_status' => 'PENDING',
                ]);
            }
        }

        // EXTENDED: compare proposed to current extended values
        $currentExtended = ExtendedFieldService::getValuesForProfile($profile);
        $extendedKeysToCheck = array_unique(array_merge(array_keys($currentExtended), array_keys($proposedExtended)));
        foreach ($extendedKeysToCheck as $fieldKey) {
            if (ProfileFieldLockService::isLocked($profile, $fieldKey)) {
                continue;
            }
            $current = $currentExtended[$fieldKey] ?? null;
            $proposed = array_key_exists($fieldKey, $proposedExtended) ? $proposedExtended[$fieldKey] : null;
            $current = self::normalize($current);
            $proposed = self::normalize($proposed);
            if (self::valuesDiffer($current, $proposed)) {
                $created[] = ConflictRecord::create([
                    'profile_id' => $profile->id,
                    'field_name' => $fieldKey,
                    'field_type' => 'EXTENDED',
                    'old_value' => $current === null ? null : (string) $current,
                    'new_value' => $proposed === null ? null : (string) $proposed,
                    'source' => 'SYSTEM',
                    'detected_at' => now(),
                    'resolution_status' => 'PENDING',
                ]);
            }
        }

        return $created;
    }

    private static function getCurrentCoreValue(MatrimonyProfile $profile, string $fieldKey): mixed
    {
        if ($fieldKey === 'gender') {
            return $profile->getAttribute('gender') ?? $profile->user?->gender ?? null;
        }
        return $profile->getAttribute($fieldKey);
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = is_string($value) ? trim($value) : (string) $value;
        return $s === '' ? null : $s;
    }

    private static function valuesDiffer(?string $a, ?string $b): bool
    {
        return (string) ($a ?? '') !== (string) ($b ?? '');
    }
}
