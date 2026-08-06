<?php

namespace App\Services;

use App\Models\Plan;

/**
 * Immutable purchase-time copy for {@code subscriptions.meta.checkout_snapshot}:
 * quota payloads + non-quota PlanFeature values ({@see PlanFeatureContractSource}).
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
