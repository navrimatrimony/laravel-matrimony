<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3B SSOT for non-quota member PlanFeature values (subscription contract):
 * paid subscription → {@code checkout_snapshot.features} only;
 * no subscription / free tier / catalog display → live {@see Plan::$features} (Keep intentionally).
 *
 * Quota-engine keys never live here — use {@see PlanQuotaUiSource}.
 * Catalog writers ({@see featuresMapForPlan}) and backfills stay; do not treat them as dead code.
 */
final class PlanFeatureContractSource
{
    /**
     * @var array<string, true>
     */
    private static array $loggedMissingFeatureContract = [];

    /**
     * Purchase-time copy of non-quota {@see \App\Models\PlanFeature} rows.
     *
     * @return array<string, string>
     */
    public static function featuresMapForPlan(Plan $plan): array
    {
        $plan->loadMissing('features');
        $out = [];
        foreach ($plan->features as $feature) {
            $key = trim((string) $feature->key);
            if ($key === '' || PlanQuotaPolicyKeys::isForbiddenPlanFeatureRowKey($key)) {
                continue;
            }
            $out[$key] = (string) ($feature->value ?? '');
        }

        return $out;
    }

    /**
     * Resolve a non-quota feature value for member access.
     * Returns null when the contract has no entry (safe defaults at call sites).
     */
    public static function valueForUser(User $user, string $key): ?string
    {
        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);
        if (PlanQuotaPolicyKeys::isForbiddenPlanFeatureRowKey($normalized)) {
            return null;
        }

        $sub = app(ActivePlanResolver::class)->getActiveSubscription($user);
        if ($sub instanceof Subscription) {
            return self::valueForSubscription($sub, $normalized);
        }

        $plan = app(ActivePlanResolver::class)->get($user);
        $plan->loadMissing('features');

        return $plan->featureValue($normalized);
    }

    /**
     * @return string|null Null when snapshot map is missing or key absent (never live PlanFeature).
     */
    public static function valueForSubscription(Subscription $subscription, string $key): ?string
    {
        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);
        if (PlanQuotaPolicyKeys::isForbiddenPlanFeatureRowKey($normalized)) {
            return null;
        }

        $snap = $subscription->checkoutSnapshot();
        $features = $snap['features'] ?? null;
        if (is_array($features) && array_key_exists($normalized, $features)) {
            $raw = $features[$normalized];

            return $raw === null ? null : (string) $raw;
        }

        self::logMissingOnce($subscription, $normalized, is_array($features) ? 'key' : 'map');

        return null;
    }

    /**
     * Phase 3B: fill missing checkout_snapshot.features from current plan catalog (backfill / repair only).
     *
     * @return bool True when meta was updated
     */
    public static function backfillCheckoutSnapshotFeatures(Subscription $subscription): bool
    {
        $meta = is_array($subscription->meta) ? $subscription->meta : [];
        $snap = isset($meta['checkout_snapshot']) && is_array($meta['checkout_snapshot'])
            ? $meta['checkout_snapshot']
            : [];

        if (isset($snap['features']) && is_array($snap['features']) && $snap['features'] !== []) {
            return false;
        }

        $subscription->loadMissing('plan.features');
        $plan = $subscription->plan;
        if (! $plan instanceof Plan) {
            $snap['features'] = [];
        } else {
            $snap['features'] = self::featuresMapForPlan($plan);
        }

        $meta['checkout_snapshot'] = $snap;
        $subscription->meta = $meta;
        $subscription->save();

        return true;
    }

    private static function logMissingOnce(Subscription $subscription, string $key, string $kind): void
    {
        $logKey = (int) $subscription->id.'|'.$key.'|'.$kind;
        if (isset(self::$loggedMissingFeatureContract[$logKey])) {
            return;
        }
        self::$loggedMissingFeatureContract[$logKey] = true;
        Log::warning('subscription_checkout_snapshot_missing_features', [
            'subscription_id' => (int) $subscription->id,
            'user_id' => (int) $subscription->user_id,
            'missing_key' => $key,
            'missing_kind' => $kind,
            'fallback' => null,
        ]);
    }
}
