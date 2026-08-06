<?php

namespace App\Services;

use App\Models\Subscription;
use App\Support\PlanFeatureKeys;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spotlight ordering: paid boosts and {@see PlanFeatureKeys::PRIORITY_LISTING} surface first (transparent to users).
 *
 * Priority listing is satisfied by (1) an active {@see \App\Models\ProfileBoost}, (2) a non-revoked
 * {@see \App\Models\UserEntitlement} with an explicit truthy {@code value_override} (admin/coupon grant —
 * plan-assigned entitlement rows alone are not enough because every quota key gets a row), or (3) an
 * effectively active subscription whose purchase contract has priority enabled in
 * {@code subscriptions.meta.checkout_snapshot.quota_policies.priority_listing} — never live
 * {@see \App\Models\PlanQuotaPolicy} catalog rows (Phase 3C).
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
        $priorityJsonPath = '$.checkout_snapshot.quota_policies.'.$priorityKey.'.is_enabled';

        $driver = $query->getConnection()->getDriverName();
        $graceExpr = match ($driver) {
            'mysql', 'mariadb' => 'DATE_ADD(s.ends_at, INTERVAL COALESCE(s.grace_period_days, 0) DAY)',
            'sqlite' => "datetime(s.ends_at, '+' || COALESCE(s.grace_period_days, 0) || ' days')",
            'pgsql' => "s.ends_at + (COALESCE(s.grace_period_days, 0) || ' days')::interval",
            default => 's.ends_at',
        };
        $activePeriod = '(s.ends_at IS NULL OR s.ends_at > ? OR (s.ends_at IS NOT NULL AND s.ends_at <= ? AND '.$graceExpr.' > ?))';

        [$priorityEnabledSql, $priorityBindings] = self::priorityEnabledFromCheckoutSnapshotSql($driver, $priorityJsonPath, $priorityKey);

        $planExistsSql = 'EXISTS (
                SELECT 1 FROM subscriptions s
                WHERE s.user_id = matrimony_profiles.user_id
                AND s.status = ?
                AND '.$activePeriod.'
                AND '.$priorityEnabledSql.'
            )';

        $headBindings = [$now, $now, $priorityKey, $now];
        $planBindings = array_merge(
            [Subscription::STATUS_ACTIVE, $now, $now, $now],
            $priorityBindings
        );

        $query->orderByRaw(
            '(CASE WHEN (
                EXISTS (SELECT 1 FROM profile_boosts pb WHERE pb.user_id = matrimony_profiles.user_id AND pb.starts_at <= ? AND pb.ends_at > ?)
                OR EXISTS (SELECT 1 FROM user_entitlements ue WHERE ue.user_id = matrimony_profiles.user_id AND ue.entitlement_key = ? AND ue.revoked_at IS NULL AND (ue.valid_until IS NULL OR ue.valid_until > ?) AND ue.value_override IN (\'1\', \'true\', \'yes\', \'on\'))
                OR '.$planExistsSql.'
            ) THEN 0 ELSE 1 END)',
            array_merge($headBindings, $planBindings)
        );
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private static function priorityEnabledFromCheckoutSnapshotSql(string $driver, string $priorityJsonPath, string $priorityKey): array
    {
        return match ($driver) {
            'mysql', 'mariadb' => [
                '(JSON_EXTRACT(s.meta, ?) = true OR JSON_UNQUOTE(JSON_EXTRACT(s.meta, ?)) IN (\'1\', \'true\'))',
                [$priorityJsonPath, $priorityJsonPath],
            ],
            'sqlite' => [
                '(json_extract(s.meta, ?) IN (1, \'1\', \'true\'))',
                [$priorityJsonPath],
            ],
            'pgsql' => [
                '((s.meta::jsonb)#>>?) IN (\'true\', \'1\')',
                ['{checkout_snapshot,quota_policies,'.$priorityKey.',is_enabled}'],
            ],
            default => ['0', []],
        };
    }
}
