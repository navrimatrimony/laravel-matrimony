<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Services\FeatureUsageService;
use App\Services\PlanQuotaPolicyMirror;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Database\Seeder;

/**
 * Gendered catalog quotas are owned by {@see SubscriptionPlansSeeder} ({@see PlanQuotaPolicy} SSOT).
 * This seeder only refreshes legacy {@see PlanFeature} mirrors from policies (does not clobber refresh_type).
 */
class PlanStandardFeatureKeysSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Plan::query()->cursor() as $plan) {
            if (! preg_match('/^(free|basic|silver|gold)_(male|female)$/', (string) $plan->slug)) {
                continue;
            }

            $plan->load('quotaPolicies');
            $rows = [];
            foreach ($plan->quotaPolicies as $policy) {
                $payload = PlanQuotaPolicyMirror::payloadFromModel($policy);
                foreach (PlanQuotaPolicyMirror::mirroredFeatureRowsFromPolicyPayload((string) $policy->feature_key, $payload) as $row) {
                    $rows[$row['key']] = $row['value'];
                }
                if ((string) $policy->feature_key === PlanFeatureKeys::INTEREST_VIEW_LIMIT) {
                    $rt = PlanQuotaPolicy::normalizeRefreshType((string) $policy->refresh_type);
                    $rows[PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD] = match ($rt) {
                        PlanQuotaPolicy::REFRESH_WEEKLY => 'weekly',
                        PlanQuotaPolicy::REFRESH_QUARTERLY => 'quarterly',
                        PlanQuotaPolicy::REFRESH_LIFETIME => 'lifetime',
                        PlanQuotaPolicy::REFRESH_UNLIMITED => 'unlimited',
                        PlanQuotaPolicy::REFRESH_DAILY => 'daily',
                        default => 'monthly',
                    };
                }
                if ((string) $policy->feature_key === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
                    $rows[FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS] = $policy->is_enabled ? '1' : '0';
                }
            }

            // Preserve non-quota extras already on the plan (biodata, chat images, etc.).
            $extras = PlanFeature::query()
                ->where('plan_id', $plan->id)
                ->whereNotIn('key', PlanQuotaPolicyKeys::planFeatureKeysWrittenByPolicies())
                ->pluck('value', 'key')
                ->all();

            foreach ($extras as $key => $value) {
                $rows[(string) $key] = (string) $value;
            }

            foreach ($rows as $key => $value) {
                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'key' => $key],
                    ['value' => (string) $value],
                );
            }
        }
    }
}
