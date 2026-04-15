<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use Illuminate\Database\Seeder;

/**
 * Gendered catalog rows are fully seeded in {@see SubscriptionPlansSeeder}.
 * This seeder only re-syncs {@see PlanQuotaPolicy} from current plan_features when those plans exist (idempotent).
 */
class PlanStandardFeatureKeysSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Plan::query()->cursor() as $plan) {
            if (! preg_match('/^(free|basic|silver|gold)_(male|female)$/', (string) $plan->slug)) {
                continue;
            }
            PlanQuotaPolicy::query()->where('plan_id', $plan->id)->delete();
            $plan->load('features');
            PlanQuotaPolicy::ensureAllForPlan($plan);
        }
    }
}
