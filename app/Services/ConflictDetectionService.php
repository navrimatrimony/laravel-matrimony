<?php

namespace App\Services;

use App\Models\ConflictRecord;
use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;

/**
 * Phase-3 Day-13 / Phase-5: Conflict detection with Escalation Matrix.
 * Compares profile current vs proposed; creates ConflictRecords per escalation rules.
 * Does NOT mutate profile, change lifecycle, or write history.
 * CORE classification from FieldRegistry; identity-critical vs dynamic from contract.
 *
 * Rule: When profile.lifecycle_state == 'draft', do NOT treat identity-critical field
 * differences as conflict; allow initial identity population. Escalation matrix
 * applies only when lifecycle_state != 'draft'.
 */
class ConflictDetectionService
{
    /** Identity-critical fields: change → conflict; under serious_intent → requires admin. Phase-5: *_id for master lookups. */
    private const IDENTITY_CRITICAL_KEYS = [
        'full_name',
        'date_of_birth',
        'gender_id',
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'marital_status_id',
        'primary_contact_number',
        'serious_intent_id',
    ];

    /** Dynamic fields: value diff does NOT create conflict; apply with history only. */
    private const DYNAMIC_KEYS = [
        'annual_income',
        'family_income',
        'occupation_title',
        'company_name',
        'work_city_id',
        'work_state_id',
    ];

    /**
     * Run conflict detection with Escalation Matrix; returns full result (records + requiresAdminResolution).
     *
     * @param  array<string, mixed>  $proposedCore
     * @param  array<string, mixed>  $proposedExtended
     * @return ConflictDetectionResult
     */
    public static function detectResult(
        MatrimonyProfile $profile,
        array $proposedCore = [],
        array $proposedExtended = [],
    ): ConflictDetectionResult {
        $created = [];
        $requiresAdmin = false;
        $seriousIntentId = $profile->serious_intent_id ?? null;
        $seriousIntentActive = $seriousIntentId !== null && $seriousIntentId !== '';

        $coreFieldKeys = self::getCoreFieldKeysFromRegistry();

        foreach ($coreFieldKeys as $fieldKey) {
            if (!array_key_exists($fieldKey, $proposedCore)) {
                continue;
            }
            if (ProfileFieldLockService::isLocked($profile, $fieldKey)) {
                continue;
            }
            $current = self::getCurrentCoreValue($profile, $fieldKey);
            $proposed = self::normalize($proposedCore[$fieldKey]);
            if (!self::valuesDiffer($current, $proposed)) {
                continue;
            }

            if (self::isDynamicField($fieldKey) && !$seriousIntentActive) {
                continue;
            }

            // Draft: allow full initial population from intake; do not create conflicts for any field.
            if (self::isDraft($profile)) {
                continue;
            }

            // One field = one PENDING conflict max; do not create duplicate.
            if (ConflictRecord::where('profile_id', $profile->id)->where('field_name', $fieldKey)->where('resolution_status', 'PENDING')->exists()) {
                continue;
            }

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

            if (self::isIdentityCriticalField($fieldKey) && $seriousIntentActive) {
                $requiresAdmin = true;
            }
        }

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
                if (ConflictRecord::where('profile_id', $profile->id)->where('field_name', $fieldKey)->where('resolution_status', 'PENDING')->exists()) {
                    continue;
                }
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

        return new ConflictDetectionResult($created, $requiresAdmin);
    }

    /**
     * Run conflict detection; returns conflict records array (backward compatible).
     * Escalation matrix is applied internally; use detectResult() when requiresAdminResolution is needed.
     *
     * @param  array<string, mixed>  $proposedCore
     * @param  array<string, mixed>  $proposedExtended
     * @return ConflictRecord[]
     */
    public static function detect(
        MatrimonyProfile $profile,
        array $proposedCore = [],
        array $proposedExtended = [],
    ): array {
        return self::detectResult($profile, $proposedCore, $proposedExtended)->conflictRecords;
    }

    /** Fallback CORE keys when registry is empty (schema-bound). Phase-5: *_id for master lookups. */
    private const FALLBACK_CORE_KEYS = [
        'full_name', 'gender_id', 'date_of_birth', 'marital_status_id', 'highest_education',
        'location', 'religion_id', 'caste_id', 'sub_caste_id', 'height_cm', 'profile_photo',
        'complexion_id', 'physical_build_id', 'blood_group_id', 'family_type_id', 'income_currency_id',
    ];

    /**
     * CORE field keys from FieldRegistry (single source). Excludes archived; respects is_enabled when present.
     * Falls back to FALLBACK_CORE_KEYS when registry has no CORE rows.
     *
     * @return array<int, string>
     */
    private static function getCoreFieldKeysFromRegistry(): array
    {
        $query = FieldRegistry::where('field_type', 'CORE')
            ->where(function ($q) {
                $q->where('is_archived', false)->orWhereNull('is_archived');
            })
            ->whereNull('replaced_by_field');
        if (\Illuminate\Support\Facades\Schema::hasColumn((new FieldRegistry)->getTable(), 'is_enabled')) {
            $query->where(function ($q) {
                $q->where('is_enabled', true)->orWhereNull('is_enabled');
            });
        }
        $keys = $query->pluck('field_key')->values()->all();
        return $keys !== [] ? $keys : self::FALLBACK_CORE_KEYS;
    }

    private static function isDraft(MatrimonyProfile $profile): bool
    {
        return ($profile->lifecycle_state ?? '') === 'draft';
    }

    private static function isIdentityCriticalField(string $fieldKey): bool
    {
        return in_array($fieldKey, self::IDENTITY_CRITICAL_KEYS, true);
    }

    private static function isDynamicField(string $fieldKey): bool
    {
        return in_array($fieldKey, self::DYNAMIC_KEYS, true);
    }

    private static function getCurrentCoreValue(MatrimonyProfile $profile, string $fieldKey): mixed
    {
        if ($fieldKey === 'gender_id') {
            return $profile->getAttribute('gender_id');
        }
        if ($fieldKey === 'primary_contact_number') {
            return \Illuminate\Support\Facades\DB::table('profile_contacts')
                ->where('profile_id', $profile->id)
                ->where('is_primary', true)
                ->value('phone_number');
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
