<?php

namespace App\Services;

use App\Models\MatrimonyProfile;

/*
|--------------------------------------------------------------------------
| ProfileCompletenessService (SSOT Day-7 — Recovery-Day-R3, Day-16)
|--------------------------------------------------------------------------
|
| Centralized completeness: (filled mandatory / total mandatory) × 100.
| Mandatory fields are read dynamically from ProfileFieldConfigurationService.
| Same logic for demo and real profiles.
|
*/
class ProfileCompletenessService
{
    public const THRESHOLD = 70;

    /**
     * Compute completeness percentage (0–100) for a profile.
     * Uses database-driven mandatory field configuration.
     * Only considers enabled mandatory fields (Day-18 enforcement).
     */
    public static function percentage(MatrimonyProfile $profile): int
    {
        $mandatoryFields = ProfileFieldConfigurationService::getMandatoryFieldKeys();
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        
        // Only consider mandatory fields that are also enabled
        $enabledMandatoryFields = array_intersect($mandatoryFields, $enabledFields);
        
        $filled = 0;
        $total = count($enabledMandatoryFields);

        if ($total === 0) {
            return 0;
        }

        foreach ($enabledMandatoryFields as $fieldKey) {
            if (self::isFieldFilled($profile, $fieldKey)) {
                $filled++;
            }
        }

        return (int) round(($filled / $total) * 100);
    }

    /**
     * Check if a specific field is filled for a profile.
     *
     * @param MatrimonyProfile $profile
     * @param string $fieldKey
     * @return bool
     */
    private static function isFieldFilled(MatrimonyProfile $profile, string $fieldKey): bool
    {
        switch ($fieldKey) {
            case 'gender':
                $gender = $profile->gender ?? $profile->user?->gender ?? null;
                return $gender !== null && $gender !== '';

            case 'date_of_birth':
                return $profile->date_of_birth !== null && $profile->date_of_birth !== '';

            case 'marital_status':
                return $profile->marital_status !== null && $profile->marital_status !== '';

            case 'education':
                return $profile->education !== null && $profile->education !== '';

            case 'location':
                return $profile->location !== null && $profile->location !== '';

            case 'profile_photo':
                return $profile->profile_photo !== null && $profile->profile_photo !== ''
                    && $profile->photo_approved !== false;

            case 'caste':
                return $profile->caste !== null && $profile->caste !== '';

            default:
                // For any other field, check if it's not null and not empty string
                $value = $profile->getAttribute($fieldKey);
                return $value !== null && $value !== '';
        }
    }

    /**
     * Whether profile meets 70% threshold.
     */
    public static function meetsThreshold(MatrimonyProfile $profile): bool
    {
        return self::percentage($profile) >= self::THRESHOLD;
    }

    /**
     * Raw SQL condition for "meets 70% OR visibility_override".
     * Uses MatrimonyProfile fields only. Table alias optional.
     * Dynamically builds SQL based on enabled mandatory fields from database (Day-18 enforcement).
     */
    public static function sqlSearchVisible(string $table = 'matrimony_profiles'): string
    {
        $mandatoryFields = ProfileFieldConfigurationService::getMandatoryFieldKeys();
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        
        // Only consider mandatory fields that are also enabled
        $enabledMandatoryFields = array_intersect($mandatoryFields, $enabledFields);
        $t = $table;

        if (empty($enabledMandatoryFields)) {
            // Fallback: if no enabled mandatory fields configured, return condition that always passes
            return "({$t}.visibility_override = 1)";
        }

        $fieldConditions = [];
        foreach ($enabledMandatoryFields as $fieldKey) {
            $fieldConditions[] = self::getFieldSqlCondition($t, $fieldKey);
        }

        $totalFields = count($enabledMandatoryFields);
        $pct = '(' . implode(' + ', $fieldConditions) . ') / ' . $totalFields . '.0 * 100';

        return "({$pct} >= " . self::THRESHOLD . " OR {$t}.visibility_override = 1)";
    }

    /**
     * Get SQL condition for checking if a field is filled.
     *
     * @param string $table Table alias
     * @param string $fieldKey Field key from configuration
     * @return string SQL CASE expression
     */
    private static function getFieldSqlCondition(string $table, string $fieldKey): string
    {
        switch ($fieldKey) {
            case 'gender':
                // Gender can come from profile or user table, but in SQL we only check profile
                // Note: This assumes gender is stored in matrimony_profiles table
                return "(CASE WHEN COALESCE(TRIM({$table}.gender),'') != '' THEN 1 ELSE 0 END)";

            case 'date_of_birth':
                return "(CASE WHEN {$table}.date_of_birth IS NOT NULL AND {$table}.date_of_birth != '' THEN 1 ELSE 0 END)";

            case 'marital_status':
                return "(CASE WHEN COALESCE(TRIM({$table}.marital_status),'') != '' THEN 1 ELSE 0 END)";

            case 'education':
                return "(CASE WHEN COALESCE(TRIM({$table}.education),'') != '' THEN 1 ELSE 0 END)";

            case 'location':
                return "(CASE WHEN ({$table}.city_id IS NOT NULL OR {$table}.taluka_id IS NOT NULL OR {$table}.district_id IS NOT NULL OR {$table}.state_id IS NOT NULL OR {$table}.country_id IS NOT NULL) THEN 1 ELSE 0 END)";

            case 'profile_photo':
                return "(CASE WHEN COALESCE(TRIM({$table}.profile_photo),'') != '' AND ({$table}.photo_approved = 1 OR {$table}.photo_approved IS TRUE) THEN 1 ELSE 0 END)";

            case 'caste':
                return "(CASE WHEN COALESCE(TRIM({$table}.caste),'') != '' THEN 1 ELSE 0 END)";

            default:
                // Generic check for any other field
                return "(CASE WHEN COALESCE(TRIM({$table}.{$fieldKey}),'') != '' THEN 1 ELSE 0 END)";
        }
    }
}
