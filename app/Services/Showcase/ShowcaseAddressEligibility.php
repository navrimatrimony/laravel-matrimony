<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Support\Location\AddressSchemaEnumOptions;

/**
 * Which {@code addresses} rows may anchor showcase profiles (admin-configured {@code hierarchy} + {@code tag}).
 *
 * Hard rule: showcase "city" picks are village hierarchy rows classified by {@code tag}; {@code hierarchy = city} is invalid.
 */
final class ShowcaseAddressEligibility
{
    public const SETTING_HIERARCHIES_KEY = 'showcase_eligible_address_hierarchies';

    public const SETTING_TAGS_KEY = 'showcase_eligible_address_tags';

    /**
     * @return list<string>
     */
    public static function defaultHierarchies(): array
    {
        return ['district', 'village'];
    }

    /**
     * @return list<string>
     */
    public static function defaultTags(): array
    {
        return ['city', 'suburban'];
    }

    /**
     * Global admin setting (auto-engine + residence resolver + bulk when policy omits overrides).
     *
     * @return list<string>
     */
    public static function globalHierarchies(): array
    {
        return self::normalizeHierarchiesList(
            json_decode((string) AdminSetting::getValue(self::SETTING_HIERARCHIES_KEY, ''), true)
        ) ?? self::defaultHierarchies();
    }

    /**
     * @return list<string>
     */
    public static function globalTags(): array
    {
        return self::normalizeTagsList(
            json_decode((string) AdminSetting::getValue(self::SETTING_TAGS_KEY, ''), true)
        ) ?? self::defaultTags();
    }

    /**
     * Bulk policy overrides {@code eligible_address_tags}; bulk hierarchies are unused (always empty in stored policy).
     *
     * @param  array<string, mixed>|null  $bulkPolicy  normalized {@see ShowcaseBulkCreateSettings::policy}
     * @return list<string>
     */
    public static function hierarchiesForContext(?array $bulkPolicy): array
    {
        if ($bulkPolicy !== null) {
            $raw = $bulkPolicy['eligible_address_hierarchies'] ?? null;
            if (is_array($raw) && $raw !== []) {
                $t = self::normalizeHierarchiesList($raw);
                if ($t !== []) {
                    return $t;
                }
            }

            // Admin bulk policy is tag-only: internal pool logic always allows district + village leaf picks.
            return self::defaultHierarchies();
        }

        return self::globalHierarchies();
    }

    /**
     * @param  array<string, mixed>|null  $bulkPolicy
     * @return list<string>
     */
    public static function tagsForContext(?array $bulkPolicy): array
    {
        if ($bulkPolicy !== null) {
            $raw = $bulkPolicy['eligible_address_tags'] ?? null;
            if (is_array($raw) && $raw !== []) {
                $t = self::normalizeTagsList($raw);
                if ($t !== []) {
                    return self::citySqlTagsFromAdminTags($t);
                }
            }
        }

        return self::citySqlTagsFromAdminTags(self::globalTags());
    }

    /**
     * Tags to use in SQL {@code WHERE city.tag IN (...)}; defaults when empty.
     *
     * @param  list<string>  $adminTags
     * @return list<string>
     */
    public static function citySqlTagsFromAdminTags(array $adminTags): array
    {
        $adminTags = array_values(array_unique(array_map('strtolower', $adminTags)));
        $flip = array_flip(AddressSchemaEnumOptions::addressTags());
        $out = [];
        foreach ($adminTags as $t) {
            if ($t !== '' && isset($flip[$t])) {
                $out[] = $t;
            }
        }
        $out = array_values(array_unique($out));
        if ($out === []) {
            return self::defaultTags();
        }

        return $out;
    }

    /**
     * @param  list<string>  $hierarchies
     */
    public static function allowsDistrictPool(array $hierarchies): bool
    {
        return in_array('district', $hierarchies, true);
    }

    /**
     * @param  list<string>  $hierarchies
     */
    public static function allowsCityPicks(array $hierarchies): bool
    {
        return in_array('village', $hierarchies, true);
    }

    /**
     * @return list<string>|null
     */
    public static function normalizeHierarchiesList(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }
        $allowed = array_flip(AddressSchemaEnumOptions::addressHierarchies());
        $out = [];
        foreach ($raw as $v) {
            $k = strtolower(trim((string) $v));
            if ($k !== '' && isset($allowed[$k])) {
                $out[] = $k;
            }
        }
        $out = array_values(array_unique($out));

        return $out !== [] ? $out : null;
    }

    /**
     * @return list<string>|null
     */
    public static function normalizeTagsList(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }
        $allowed = array_flip(AddressSchemaEnumOptions::addressTags());
        $out = [];
        foreach ($raw as $v) {
            $k = strtolower(trim((string) $v));
            if ($k !== '' && isset($allowed[$k])) {
                $out[] = $k;
            }
        }
        $out = array_values(array_unique($out));

        return $out !== [] ? $out : null;
    }
}
