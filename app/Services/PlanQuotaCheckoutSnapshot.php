<?php

namespace App\Services;

use App\Models\Plan;

/**
 * Immutable purchase-time copy of {@see \App\Models\PlanQuotaPolicy} rows for {@code subscriptions.meta.checkout_snapshot}.
 */
final class PlanQuotaCheckoutSnapshot
{
    /**
     * @return array{quota_policies: array<string, array<string, mixed>>}
     */
    public static function forPlan(Plan $plan): array
    {
        $plan->loadMissing('quotaPolicies');
        $byKey = [];
        foreach ($plan->quotaPolicies as $policy) {
            $byKey[$policy->feature_key] = PlanQuotaPolicyMirror::payloadFromModel($policy);
        }
        PlanQuotaUiSource::assertCompleteQuotaPayloads($byKey, 'PlanQuotaCheckoutSnapshot.forPlan plan_id='.(int) $plan->id);

        return ['quota_policies' => $byKey];
    }
}
