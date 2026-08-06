<?php

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3C: backfill checkout_snapshot.quota_policies.priority_listing from current plan_quota_policies
 * (JSON only — no new tables/columns). Ranking reads this contract key, not live catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        $svc = app(SubscriptionService::class);

        Subscription::query()
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($svc): void {
                foreach ($rows as $sub) {
                    if ($sub instanceof Subscription) {
                        $svc->backfillCheckoutSnapshotPriorityListing($sub);
                    }
                }
            });
    }

    public function down(): void
    {
        // Non-destructive forward backfill; priority_listing in quota_policies is contract — do not strip on rollback.
    }
};
