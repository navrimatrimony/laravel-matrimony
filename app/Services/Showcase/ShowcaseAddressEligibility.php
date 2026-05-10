<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Support\Location\AddressSchemaEnumOptions;

/**
 * Which {@code addresses} rows may anchor showcase profiles (admin-configured {@code type} + {@code tag}).
 *
 * Hard rule: {@code type = city} and {@code tag = none} is never eligible for showcase, regardless of admin selection.
 */
final class ShowcaseAddressEligibility
{
    public const SETTING_TYPES_KEY = 'showcase_eligible_address_types';

    public const SETTING_TAGS_KEY = 'showcase_eligible_address_tags';

    /**
     * @return list<string>
     */
    public static function defaultTypes(): array
    {
        return ['district', 'city'];
    }

    /**
     * @return list<string>
     */
    public static function defaultTags(): array
    {
        return ['metro', 'capital'];
    }

    /**
     * Global admin setting (auto-engine + residence resolver + bulk when policy omits overrides).
     *
     * @return list<string>
     */
    public static function globalTypes(): array
    {
        return self::normalizeTypesList(
            json_decode((string) AdminSetting::getValue(self::SETTING_TYPES_KEY, ''), true)
        ) ?? self::defaultTypes();
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
     * Bulk policy may override {@code eligible_address_types} / {@code eligible_address_tags}.
     *
     * @param  array<string, mixed>|null  $bulkPolicy  normalized {@see ShowcaseBulkCreateSettings::policy}
     * @return list<string>
     */
    public static function typesForContext(?array $bulkPolicy): array
    {
        if ($bulkPolicy !== null) {
            $raw = $bulkPolicy['eligible_address_types'] ?? null;
            if (is_array($raw) && $raw !== []) {
                $t = self::normalizeTypesList($raw);
                if ($t !== []) {
                    return $t;
                }
            }
        }

        return self::globalTypes();
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
     * Tags to use in SQL {@code WHERE city.tag IN (...)} — never {@code none}; defaults when empty.
     *
     * @param  list<string>  $adminTags
     * @return list<string>
     */
    public static function citySqlTagsFromAdminTags(array $adminTags): array
    {
        $adminTags = array_values(array_unique(array_map('strtolower', $adminTags)));
        $allowedNonNone = array_values(array_filter(
            AddressSchemaEnumOptions::addressTags(),
            static fn (string $t) => $t !== 'none'
        ));
        $flip = array_flip($allowedNonNone);
        $out = [];
        foreach ($adminTags as $t) {
            if ($t !== '' && $t !== 'none' && isset($flip[$t])) {
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
     * @param  list<string>  $types
     */
    public static function allowsDistrictPool(array $types): bool
    {
        return in_array('district', $types, true);
    }

    /**
     * @param  list<string>  $types
     */
    public static function allowsCityPicks(array $types): bool
    {
        return in_array('city', $types, true);
    }

    /**
     * @return list<string>|null
     */
    public static function normalizeTypesList(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }
        $allowed = array_flip(AddressSchemaEnumOptions::addressTypes());
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
