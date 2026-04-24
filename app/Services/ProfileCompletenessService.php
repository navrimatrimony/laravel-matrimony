<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠️ DEPRECATED FOR DIRECT USE
 *
 * Do not use this service directly in controllers or application services for read-side completion.
 * Always use {@see ProfileCompletionEngine} for mandatory/detailed percentages and breakdown.
 *
 * This class remains the calculation layer (percentages, SQL visibility, admin keys) used by the engine and legacy call sites.
 *
 * Day-7 / Day-16: mandatory completeness uses {@see ProfileFieldConfigurationService}; same logic for showcase and real profiles.
 */
class ProfileCompletenessService
{
    /**
     * Search/discovery SQL visibility only — not used for interest when {@see interestMinimumPercent()} is 0.
     */
    public const THRESHOLD = 70;

    public const ADMIN_KEY_INTEREST_MIN_CORE_PCT = 'interest_min_core_completeness_pct';

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
     */
    private static function isFieldFilled(MatrimonyProfile $profile, string $fieldKey): bool
    {
        switch ($fieldKey) {
            case 'gender':
            case 'gender_id':
                return $profile->gender_id !== null;

            case 'date_of_birth':
                return $profile->date_of_birth !== null && $profile->date_of_birth !== '';

            case 'marital_status':
            case 'marital_status_id':
                return $profile->marital_status_id !== null;

            case 'education':
            case 'highest_education':
                if (($profile->highest_education ?? '') !== '') {
                    return true;
                }
                if (! empty($profile->education_degree_id)) {
                    return true;
                }
                if (($profile->education_text ?? '') !== '') {
                    return true;
                }
                if (! empty($profile->highest_education_id)) {
                    return true;
                }
                if (($profile->highest_education_text ?? '') !== '') {
                    return true;
                }

                return false;

            case 'location':
                return $profile->city_id !== null;

            case 'profile_photo':
                return $profile->profile_photo !== null && $profile->profile_photo !== ''
                    && $profile->photo_approved !== false;

            case 'caste':
                return $profile->caste_id !== null;

            default:
                // For any other field, check if it's not null and not empty string
                $value = $profile->getAttribute($fieldKey);

                return $value !== null && $value !== '';
        }
    }

    /**
     * Minimum core completeness % required for sending/receiving interest (mandatory-field score).
     * Stored in admin_settings; 0 = enforcement off (default).
     */
    public static function interestMinimumPercent(): int
    {
        $v = (int) AdminSetting::getValue(self::ADMIN_KEY_INTEREST_MIN_CORE_PCT, '0');

        return max(0, min(100, $v));
    }

    /**
     * Interest send / receive / accept — delegates to {@see RuleEngineService} (system_rules + admin fallback).
     *
     * @deprecated Call {@see RuleEngineService::passesInterestMandatoryCore()} directly in new code.
     */
    public static function meetsInterestCompletenessRequirement(MatrimonyProfile $profile): bool
    {
        return app(RuleEngineService::class)->passesInterestMandatoryCore($profile);
    }

    /**
     * Detailed completion across full wizard sections (not only mandatory core fields).
     * completed=1, warning=0.5, incomplete=0.
     */
    public static function detailedPercentage(MatrimonyProfile $profile): int
    {
        $sections = FieldCatalogService::getSectionKeys(false);
        if (empty($sections)) {
            return 0;
        }
        $statuses = ProfileCompletionService::getSectionStatuses($profile, $sections);
        $score = 0.0;
        foreach ($sections as $key) {
            $status = $statuses[$key] ?? 'incomplete';
            if ($status === 'completed') {
                $score += 1.0;
            } elseif ($status === 'warning') {
                $score += 0.5;
            }
        }

        return (int) round(($score / count($sections)) * 100);
    }

    /**
     * Returns both completion signals for UI clarity.
     *
     * @return array{core:int,detailed:int}
     */
    public static function breakdown(MatrimonyProfile $profile): array
    {
        return [
            'core' => self::percentage($profile),
            'detailed' => self::detailedPercentage($profile),
        ];
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
            // No enabled mandatory fields in DB (fresh install / empty profile_field_configs / all mandatory disabled).
            // MUST NOT require visibility_override — that hid every profile except admin-forced ones.
            return '(1 = 1)';
        }

        $fieldConditions = [];
        foreach ($enabledMandatoryFields as $fieldKey) {
            $fieldConditions[] = self::getFieldSqlCondition($t, $fieldKey);
        }

        $totalFields = count($enabledMandatoryFields);
        $pct = '('.implode(' + ', $fieldConditions).') / '.$totalFields.'.0 * 100';

        return "({$pct} >= ".self::THRESHOLD." OR {$t}.visibility_override = 1)";
    }

    /**
     * Get SQL condition for checking if a field is filled.
     *
     * @param  string  $table  Table alias
     * @param  string  $fieldKey  Field key from configuration
     * @return string SQL CASE expression
     */
    private static function getFieldSqlCondition(string $table, string $fieldKey): string
    {
        switch ($fieldKey) {
            case 'gender':
            case 'gender_id':
                return "(CASE WHEN {$table}.gender_id IS NOT NULL THEN 1 ELSE 0 END)";

            case 'date_of_birth':
                // DATE vs '' compare breaks MySQL 8 strict mode (HY000/1525); NULL is the only empty state for DATE.
                return "(CASE WHEN {$table}.date_of_birth IS NOT NULL THEN 1 ELSE 0 END)";

            case 'marital_status':
            case 'marital_status_id':
                return "(CASE WHEN {$table}.marital_status_id IS NOT NULL THEN 1 ELSE 0 END)";

            case 'education':
            case 'highest_education':
                $parts = ["COALESCE(TRIM({$table}.highest_education),'') != ''"];
                if (Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
                    $parts[] = "{$table}.education_degree_id IS NOT NULL";
                    $parts[] = "COALESCE(TRIM({$table}.education_text),'') != ''";
                }
                if (Schema::hasColumn('matrimony_profiles', 'highest_education_id')) {
                    $parts[] = "{$table}.highest_education_id IS NOT NULL";
                }
                if (Schema::hasColumn('matrimony_profiles', 'highest_education_text')) {
                    $parts[] = "COALESCE(TRIM({$table}.highest_education_text),'') != ''";
                }
                $cond = implode(' OR ', $parts);

                return "(CASE WHEN {$cond} THEN 1 ELSE 0 END)";

            case 'location':
                return "(CASE WHEN {$table}.city_id IS NOT NULL THEN 1 ELSE 0 END)";

            case 'profile_photo':
                return "(CASE WHEN COALESCE(TRIM({$table}.profile_photo),'') != '' AND ({$table}.photo_approved = 1 OR {$table}.photo_approved IS TRUE) THEN 1 ELSE 0 END)";

            case 'caste':
                return "(CASE WHEN {$table}.caste_id IS NOT NULL THEN 1 ELSE 0 END)";

            default:
                // Generic check for any other field
                return "(CASE WHEN COALESCE(TRIM({$table}.{$fieldKey}),'') != '' THEN 1 ELSE 0 END)";
        }
    }
}
