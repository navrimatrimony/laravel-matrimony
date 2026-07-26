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
     * Per-process memo, keyed by flag column.
     *
     * `profile_field_configs` is a small admin-owned config table (one row per profile field) whose
     * contents cannot change while a request is being served — but completeness asks for the mandatory
     * AND enabled lists once per profile, and matching computes completeness for the seeker plus every
     * candidate in the pool. That was two identical `pluck()`s per candidate for a list of ~30 strings.
     *
     * Invalidated by {@see \App\Models\ProfileFieldConfig} save/delete events, so an admin edit on the
     * field-configuration screen is visible immediately, in the same request that saved it.
     *
     * @var array<string, array<string>>
     */
    private static array $keyMemo = [];

    /**
     * Drop the memo — wired to {@see \App\Models\ProfileFieldConfig} write events.
     */
    public static function flushRuntimeCache(): void
    {
        self::$keyMemo = [];
    }

    /**
     * @return array<string> Array of field_key values
     */
    private static function keysWhere(string $flag): array
    {
        if (isset(self::$keyMemo[$flag])) {
            return self::$keyMemo[$flag];
        }

        return self::$keyMemo[$flag] = ProfileFieldConfig::where($flag, true)
            ->pluck('field_key')
            ->toArray();
    }

    /**
     * Get all field keys marked as mandatory.
     *
     * @return array<string> Array of field_key values
     */
    public static function getMandatoryFieldKeys(): array
    {
        return self::keysWhere('is_mandatory');
    }

    /**
     * Get all field keys marked as visible.
     *
     * @return array<string> Array of field_key values
     */
    public static function getVisibleFieldKeys(): array
    {
        return self::keysWhere('is_visible');
    }

    /**
     * Get all field keys marked as enabled.
     * CORE field height_cm is always included for user edit (same level as education, location, caste).
     *
     * @return array<string> Array of field_key values
     */
    public static function getEnabledFieldKeys(): array
    {
        $keys = self::keysWhere('is_enabled');

        if (! in_array('height_cm', $keys, true)) {
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
        return self::keysWhere('is_searchable');
    }
}
