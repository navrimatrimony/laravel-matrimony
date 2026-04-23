<?php

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasTable('plan_quota_policies')) {
            return;
        }

        foreach (Plan::query()->orderBy('id')->cursor() as $plan) {
            PlanQuotaPolicy::ensureAllKeysForPlan($plan);
        }
    }

    public function down(): void
    {
        // Additive integrity fix only; do not delete quota rows on rollback.
    }
};
