<?php

namespace App\Services\Matching;

/**
 * Default rows for matching_boost_rules table (mirrors legacy MatchBoostSetting singleton defaults).
 *
 * @return list<array{boost_type: string, value: int, max_cap: int, is_active: bool, meta: ?array}>
 */
final class MatchBoostSettingDefaults
{
    /**
     * @return list<array{boost_type: string, value: int, max_cap: int, is_active: bool, meta: ?array}>
     */
    public static function snapshot(): array
    {
        return [
            ['boost_type' => 'active', 'value' => 3, 'max_cap' => 100, 'is_active' => true, 'meta' => ['active_within_days' => 7]],
            ['boost_type' => 'premium', 'value' => 2, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'gold_extra', 'value' => 10, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'silver_extra', 'value' => 5, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'similarity', 'value' => 3, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'ai', 'value' => 0, 'max_cap' => 20, 'is_active' => false, 'meta' => ['ai_provider' => 'sarvam']],
            ['boost_type' => 'aggregate_cap', 'value' => 0, 'max_cap' => 20, 'is_active' => true, 'meta' => []],
        ];
    }
}
