<?php

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B: backfill checkout_snapshot.features from current PlanFeature rows
 * (JSON only — no new tables/columns).
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
                        $svc->backfillCheckoutSnapshotFeatures($sub);
                    }
                }
            });
    }

    public function down(): void
    {
        // Non-destructive forward backfill; features map is contract — do not strip on rollback.
    }
};
