<?php

use App\Models\Plan;
use App\Models\PlanTerm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paid plans get default monthly / quarterly / half-yearly / yearly terms when any are missing.
     * Existing {@see PlanTerm} rows are never updated here — see {@see PlanTerm::fillMissingTermsForPlan}.
     */
    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasTable('plan_terms')) {
            return;
        }

        foreach (Plan::query()->orderBy('id')->cursor() as $plan) {
            PlanTerm::fillMissingTermsForPlan($plan);
        }
    }

    public function down(): void
    {
        // Non-destructive fill; no rollback.
    }
};
