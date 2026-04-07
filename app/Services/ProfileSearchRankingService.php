<?php

namespace App\Services;

use App\Support\PlanFeatureKeys;
use Illuminate\Database\Eloquent\Builder;
/**
 * Spotlight ordering: paid boosts and plan {@see PlanFeatureKeys::PRIORITY_LISTING} surface first (transparent to users).
 */
class ProfileSearchRankingService
{
    /**
     * Prefix query ordering so spotlight profiles appear before normal sort / discover tie-breaks.
     *
     * @param  Builder<\App\Models\MatrimonyProfile>  $query
     */
    public static function applySpotlightFirst(Builder $query): void
    {
        $now = now()->toDateTimeString();
        $priorityKey = PlanFeatureKeys::PRIORITY_LISTING;

        $query->orderByRaw(
            '(CASE WHEN (
                EXISTS (SELECT 1 FROM profile_boosts pb WHERE pb.user_id = matrimony_profiles.user_id AND pb.starts_at <= ? AND pb.ends_at > ?)
                OR EXISTS (SELECT 1 FROM user_entitlements ue WHERE ue.user_id = matrimony_profiles.user_id AND ue.entitlement_key = ? AND ue.revoked_at IS NULL AND (ue.valid_until IS NULL OR ue.valid_until > ?))
            ) THEN 0 ELSE 1 END)',
            [$now, $now, $priorityKey, $now]
        );
    }
}
