<?php

namespace App\Services;

use App\Models\ProfileFieldConfig;

/*
|--------------------------------------------------------------------------
| ProfileFieldConfigurationService (SSOT Day-16)
|--------------------------------------------------------------------------
|
| Read-only service for profile field configuration.
| Provides database-driven field lists for completeness, visibility,
| searchability, and enablement checks.
|
| Foundation only — no write operations, no admin logic.
|
*/
class ProfileFieldConfigurationService
{
    /**
     * Get all field keys marked as mandatory.
     *
     * @return array<string> Array of field_key values
     */
    public static function getMandatoryFieldKeys(): array
    {
        return ProfileFieldConfig::where('is_mandatory', true)
            ->pluck('field_key')
            ->toArray();
    }

    /**
     * Get all field keys marked as visible.
     *
     * @return array<string> Array of field_key values
     */
    public static function getVisibleFieldKeys(): array
    {
        return ProfileFieldConfig::where('is_visible', true)
            ->pluck('field_key')
            ->toArray();
    }

    /**
     * Get all field keys marked as enabled.
     * CORE field height_cm is always included for user edit (same level as education, location, caste).
     *
     * @return array<string> Array of field_key values
     */
    public static function getEnabledFieldKeys(): array
    {
        $keys = ProfileFieldConfig::where('is_enabled', true)
            ->pluck('field_key')
            ->toArray();

        if (!in_array('height_cm', $keys, true)) {
            $keys[] = 'height_cm';
        }

        return $keys;
    }

    /**
     * Get all field keys marked as searchable.
     *
     * @return array<string> Array of field_key values
     */
    public static function getSearchableFieldKeys(): array
    {
        return ProfileFieldConfig::where('is_searchable', true)
            ->pluck('field_key')
            ->toArray();
    }
}
