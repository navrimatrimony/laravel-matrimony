<?php

use App\Models\PlanQuotaPolicy;
use App\Support\PlanFeatureKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapse duplicate refresh_type aliases total / plan_duration → lifetime.
 * Additive value rewrite only — no DROP COLUMN / DROP TABLE.
 * Runtime normalizeRefreshType() remains the safety net for any leftover snap JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        $aliases = [PlanQuotaPolicy::REFRESH_TOTAL, PlanQuotaPolicy::REFRESH_PLAN_DURATION];
        $lifetime = PlanQuotaPolicy::REFRESH_LIFETIME;

        if (Schema::hasTable('plan_quota_policies')) {
            DB::table('plan_quota_policies')
                ->whereIn('refresh_type', $aliases)
                ->update(['refresh_type' => $lifetime]);
        }

        if (Schema::hasTable('plan_features')) {
            DB::table('plan_features')
                ->where('key', PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD)
                ->whereIn('value', $aliases)
                ->update(['value' => $lifetime]);
        }

        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        DB::table('subscriptions')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($aliases, $lifetime): void {
                foreach ($rows as $row) {
                    $meta = json_decode((string) ($row->meta ?? ''), true);
                    if (! is_array($meta)) {
                        continue;
                    }
                    $snap = $meta['checkout_snapshot'] ?? null;
                    if (! is_array($snap)) {
                        continue;
                    }
                    $qp = $snap['quota_policies'] ?? null;
                    if (! is_array($qp)) {
                        continue;
                    }

                    $changed = false;
                    foreach ($qp as $featureKey => $payload) {
                        if (! is_array($payload)) {
                            continue;
                        }
                        $rt = strtolower(trim((string) ($payload['refresh_type'] ?? '')));
                        if (! in_array($rt, $aliases, true)) {
                            continue;
                        }
                        $qp[$featureKey]['refresh_type'] = $lifetime;
                        $changed = true;
                    }

                    if (! $changed) {
                        continue;
                    }

                    $snap['quota_policies'] = $qp;
                    $meta['checkout_snapshot'] = $snap;
                    DB::table('subscriptions')
                        ->where('id', $row->id)
                        ->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive forward collapse; do not reintroduce duplicate aliases.
    }
};
