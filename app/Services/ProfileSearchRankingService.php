<?php

namespace App\Services;

use App\Models\Subscription;
use App\Support\PlanFeatureKeys;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spotlight ordering: paid boosts and {@see PlanFeatureKeys::PRIORITY_LISTING} surface first (transparent to users).
 *
 * Priority listing is satisfied by either (1) a non-revoked entitlement row or (2) an effectively active subscription
 * whose plan has a truthy {@see PlanFeatureKeys::PRIORITY_LISTING} in `plan_features` (admin quota policy mirror).
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

        $driver = $query->getConnection()->getDriverName();
        $keyCol = match ($driver) {
            'mysql', 'mariadb' => 'pf.`key`',
            default => 'pf."key"',
        };
        $valCol = match ($driver) {
            'mysql', 'mariadb' => 'pf.`value`',
            default => 'pf."value"',
        };

        $pfTruthy = sprintf("(TRIM(%s) = '1' OR LOWER(TRIM(%s)) IN ('true','yes','on'))", $valCol, $valCol);

        $driver = $query->getConnection()->getDriverName();
        $graceExpr = match ($driver) {
            'mysql', 'mariadb' => 'DATE_ADD(s.ends_at, INTERVAL COALESCE(p.grace_period_days, 0) DAY)',
            'sqlite' => "datetime(s.ends_at, '+' || COALESCE(p.grace_period_days, 0) || ' days')",
            'pgsql' => "s.ends_at + (COALESCE(p.grace_period_days, 0) || ' days')::interval",
            default => 's.ends_at',
        };
        $activePeriod = '(s.ends_at IS NULL OR s.ends_at > ? OR (s.ends_at IS NOT NULL AND s.ends_at <= ? AND '.$graceExpr.' > ?))';

        $planExistsSql = 'EXISTS (
                SELECT 1 FROM subscriptions s
                INNER JOIN plans p ON p.id = s.plan_id
                INNER JOIN plan_features pf ON pf.plan_id = s.plan_id AND '.$keyCol.' = ? AND '.$pfTruthy.'
                WHERE s.user_id = matrimony_profiles.user_id
                AND s.status = ?
                AND '.$activePeriod.'
            )';

        $headBindings = [$now, $now, $priorityKey, $now];
        $planBindings = [$priorityKey, Subscription::STATUS_ACTIVE, $now, $now, $now];

        $query->orderByRaw(
            '(CASE WHEN (
                EXISTS (SELECT 1 FROM profile_boosts pb WHERE pb.user_id = matrimony_profiles.user_id AND pb.starts_at <= ? AND pb.ends_at > ?)
                OR EXISTS (SELECT 1 FROM user_entitlements ue WHERE ue.user_id = matrimony_profiles.user_id AND ue.entitlement_key = ? AND ue.revoked_at IS NULL AND (ue.valid_until IS NULL OR ue.valid_until > ?))
                OR '.$planExistsSql.'
            ) THEN 0 ELSE 1 END)',
            array_merge($headBindings, $planBindings)
        );
    }
}
