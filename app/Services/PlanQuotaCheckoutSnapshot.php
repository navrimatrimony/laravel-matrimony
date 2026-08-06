<?php

namespace App\Services;

use App\Models\Plan;

/**
 * Purchase-time freeze writer for {@code subscriptions.meta.checkout_snapshot}
 * (catalog → subscription contract): complete {@code quota_policies} map plus
 * non-quota PlanFeature values ({@see PlanFeatureContractSource::featuresMapForPlan}).
 * Keep intentionally — not dead after Phase 3; paid runtime reads the frozen snap, not live catalog.
 */
final class PlanQuotaCheckoutSnapshot
{
    /**
     * @return array{
     *     quota_policies: array<string, array<string, mixed>>,
     *     features: array<string, string>
     * }
     */
    public static function forPlan(Plan $plan): array
    {
        $plan->loadMissing(['quotaPolicies', 'features']);
        $byKey = [];
        foreach ($plan->quotaPolicies as $policy) {
            $byKey[$policy->feature_key] = PlanQuotaPolicyMirror::payloadFromModel($policy);
        }
        PlanQuotaUiSource::assertCompleteQuotaPayloads($byKey, 'PlanQuotaCheckoutSnapshot.forPlan plan_id='.(int) $plan->id);

        return [
            'quota_policies' => $byKey,
            'features' => PlanFeatureContractSource::featuresMapForPlan($plan),
        ];
    }
}
