<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Services\MatrimonyProfileSearchQueryService;

/**
 * Admin keys for auto-showcase engine (defaults when row missing).
 */
class AutoShowcaseSettings
{
    public static function engineEnabled(): bool
    {
        return AdminSetting::getBool('auto_showcase_engine_enabled', false);
    }

    public static function requireLowTotal(): bool
    {
        return AdminSetting::getBool('auto_showcase_require_low_total', true);
    }

    public static function minTotalResults(): int
    {
        return max(0, (int) AdminSetting::getValue('auto_showcase_min_total_results', '5'));
    }

    public static function requireStrictLow(): bool
    {
        return AdminSetting::getBool('auto_showcase_require_strict_low', true);
    }

    /** Inclusive upper bound: trigger when strict_count <= this value. */
    public static function strictMax(): int
    {
        return max(0, (int) AdminSetting::getValue('auto_showcase_strict_max', '0'));
    }

    /**
     * @return list<string>
     */
    public static function strictDimensionKeys(): array
    {
        $raw = (string) AdminSetting::getValue(
            'auto_showcase_strict_field_keys',
            '["religion_id","caste_id","district_id","city_id","date_of_birth","marital_status_id"]'
        );
        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? MatrimonyProfileSearchQueryService::normalizeStrictKeys(array_map('strval', $decoded))
            : ['religion_id', 'caste_id', 'district_id', 'city_id', 'date_of_birth', 'marital_status_id'];
    }

    /**
     * Ordered residence fallback modes: search_city | district_seat | min_population
     *
     * @return list<string>
     */
    public static function residenceFallbackOrder(): array
    {
        $raw = (string) AdminSetting::getValue(
            'auto_showcase_residence_fallback',
            '["search_city","district_seat","min_population"]'
        );
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || $decoded === []) {
            return ['search_city', 'district_seat', 'min_population'];
        }
        $allowed = ['search_city', 'district_seat', 'min_population'];
        $out = [];
        foreach ($decoded as $v) {
            $k = strtolower(trim((string) $v));
            if (in_array($k, $allowed, true)) {
                $out[] = $k;
            }
        }

        return $out !== [] ? array_values(array_unique($out)) : ['search_city', 'district_seat', 'min_population'];
    }

    public static function minPopulationThreshold(): int
    {
        return max(0, (int) AdminSetting::getValue('auto_showcase_min_population', '100000'));
    }

    public static function perSearchMaxCreate(): int
    {
        return max(0, (int) AdminSetting::getValue('auto_showcase_per_search_max_create', '1'));
    }

    public static function dailyUserCap(): int
    {
        return max(0, (int) AdminSetting::getValue('auto_showcase_daily_user_cap', '3'));
    }

    /** draft | active — admin bulk showcase create (Showcase profile bulk UI). Default draft when unset. */
    public static function bulkShowcaseLifecycle(): string
    {
        $v = AdminSetting::getValue('showcase_bulk_create_lifecycle');
        if ($v !== null && trim((string) $v) !== '') {
            return strtolower(trim((string) $v)) === 'active' ? 'active' : 'draft';
        }

        $legacy = self::legacySingleLifecycleState();

        return $legacy ?? 'draft';
    }

    /**
     * draft | active — profiles created by the auto-showcase engine after member search.
     *
     * When showcase_auto_engine_lifecycle is unset, returns active. We intentionally do not fall back to
     * legacy auto_showcase_lifecycle_state here: that key applied to both bulk and engine before the split,
     * and was often draft for bulk — which incorrectly forced engine-created profiles to draft even when
     * the Search tab shows Active (after save) or when admins expect the documented engine default.
     */
    public static function autoEngineShowcaseLifecycle(): string
    {
        $v = AdminSetting::getValue('showcase_auto_engine_lifecycle');
        if ($v !== null && trim((string) $v) !== '') {
            return strtolower(trim((string) $v)) === 'active' ? 'active' : 'draft';
        }

        return 'active';
    }

    /** Legacy single key before bulk vs engine split; null if never set. */
    private static function legacySingleLifecycleState(): ?string
    {
        $row = AdminSetting::query()->where('key', 'auto_showcase_lifecycle_state')->first();
        if ($row === null || trim((string) $row->value) === '') {
            return null;
        }

        return strtolower(trim((string) $row->value)) === 'active' ? 'active' : 'draft';
    }

    /**
     * @return list<int>
     */
    public static function religionAllowlistIds(): array
    {
        $raw = (string) AdminSetting::getValue('auto_showcase_religion_allowlist', '[]');
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $decoded))));
    }
}
