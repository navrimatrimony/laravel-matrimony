<?php

use App\Models\PlanTerm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plan_terms')) {
            return;
        }

        if (! Schema::hasColumn('plan_terms', 'quota_bonus_percent')) {
            Schema::table('plan_terms', function (Blueprint $table) {
                $table->unsignedTinyInteger('quota_bonus_percent')->default(0);
            });
        }

        foreach (PlanTerm::presetBillingKeys() as $billingKey) {
            DB::table('plan_terms')
                ->where('billing_key', $billingKey)
                ->update([
                    'quota_bonus_percent' => PlanTerm::defaultQuotaBonusPercentFor($billingKey),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plan_terms') || ! Schema::hasColumn('plan_terms', 'quota_bonus_percent')) {
            return;
        }

        Schema::table('plan_terms', function (Blueprint $table) {
            $table->dropColumn('quota_bonus_percent');
        });
    }
};
