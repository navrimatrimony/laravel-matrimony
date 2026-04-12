<?php

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Services\EntitlementService;
use App\Support\PlanFeatureKeys;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Additive: new plan_features key for freemium who-viewed (calendar month, distinct viewers).
     */
    public function up(): void
    {
        foreach (Plan::query()->cursor() as $plan) {
            $exists = PlanFeature::query()
                ->where('plan_id', $plan->id)
                ->where('key', PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT)
                ->exists();
            if ($exists) {
                continue;
            }
            $default = strtolower((string) $plan->slug) === 'free' ? '5' : '0';
            PlanFeature::query()->create([
                'plan_id' => $plan->id,
                'key' => PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT,
                'value' => $default,
            ]);
        }

        $userIds = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->pluck('user_id')
            ->unique()
            ->filter()
            ->all();

        $entitlements = app(EntitlementService::class);
        foreach ($userIds as $userId) {
            $entitlements->resyncFromActiveSubscription((int) $userId);
        }
    }

    public function down(): void
    {
        PlanFeature::query()->where('key', PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT)->delete();
    }
};
