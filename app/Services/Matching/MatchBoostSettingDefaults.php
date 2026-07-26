<?php

namespace App\Services\Matching;

/**
 * Default rows for the `matching_boost_rules` table — the single admin-tunable source of truth for
 * every ranking adjustment applied on top of the base 0-100 compatibility score by
 * {@see \App\Services\MatchBoostService}.
 *
 * Ordering rationale (2026-07-26 trust/quality rebalance):
 * this product is matchmaker-driven, so the top of a list must be *usable* — a candidate who can be
 * reached, whose identity is checked, whose profile is filled in and carries a photo, and who has not
 * been abandoned. A paid plan is a commercial signal, not a quality signal, so it is deliberately
 * ranked below every single quality signal (max +4 combined) and is the first thing trimmed when the
 * aggregate cap is hit.
 *
 * verification (kyc 7 + mobile 5 = 12) > photo 7 > completeness 6 > recency 5 > similarity 3 > paid tier 4
 */
final class MatchBoostSettingDefaults
{
    /**
     * @return list<array{boost_type: string, value: int, max_cap: int, is_active: bool, meta: ?array}>
     */
    public static function snapshot(): array
    {
        return [
            // ---- Trust / quality signals (candidate-intrinsic; no actor context needed) ----
            // Admin-reviewed ID document approved.
            ['boost_type' => 'verified_kyc', 'value' => 7, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            // Approved, non-placeholder, actually-stored photo (MatrimonyProfile::hasApprovedPublicPhoto()).
            ['boost_type' => 'photo', 'value' => 7, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            // Graded on ProfileCompletionEngine score; nothing is earned at or below `min_percent`.
            ['boost_type' => 'completeness', 'value' => 6, 'max_cap' => 100, 'is_active' => true, 'meta' => ['min_percent' => 50]],
            // users.mobile_verified_at — reachability is the matchmaker's first requirement.
            ['boost_type' => 'verified_mobile', 'value' => 5, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            // Recency: full value inside `active_within_days`, linear decay to 0 at `stale_after_days`.
            ['boost_type' => 'active', 'value' => 5, 'max_cap' => 100, 'is_active' => true, 'meta' => ['active_within_days' => 7, 'stale_after_days' => 180]],

            // ---- Pair signals ----
            ['boost_type' => 'similarity', 'value' => 3, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'ai', 'value' => 0, 'max_cap' => 20, 'is_active' => false, 'meta' => ['ai_provider' => 'sarvam']],

            // ---- Commercial tier (lowest priority; trimmed first under the aggregate cap) ----
            ['boost_type' => 'premium', 'value' => 2, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'gold_extra', 'value' => 2, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'silver_extra', 'value' => 1, 'max_cap' => 100, 'is_active' => true, 'meta' => []],

            // Global ceiling for rule + AI boost. Read from `max_cap`, not `value`.
            ['boost_type' => 'aggregate_cap', 'value' => 0, 'max_cap' => 25, 'is_active' => true, 'meta' => []],
        ];
    }
}
