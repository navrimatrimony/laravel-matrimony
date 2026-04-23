<?php

use App\Models\PlanQuotaPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plan_quota_policies')) {
            return;
        }

        PlanQuotaPolicy::query()->whereNotNull('policy_meta')->each(function (PlanQuotaPolicy $row): void {
            $m = $row->policy_meta;
            if (! is_array($m) || ! array_key_exists('interest_view_reset_period', $m)) {
                return;
            }
            unset($m['interest_view_reset_period']);
            $row->policy_meta = $m === [] ? null : $m;
            $row->save();
        });
    }

    public function down(): void
    {
        // Non-destructive: do not restore legacy policy_meta keys.
    }
};
