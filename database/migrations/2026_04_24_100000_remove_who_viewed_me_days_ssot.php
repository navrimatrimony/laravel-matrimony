<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_quota_policies')) {
            DB::table('plan_quota_policies')->where('feature_key', 'who_viewed_me_days')->delete();
        }
        if (Schema::hasTable('plan_features')) {
            DB::table('plan_features')->where('key', 'who_viewed_me_days')->delete();
        }
        if (Schema::hasTable('referral_reward_rules') && Schema::hasColumn('referral_reward_rules', 'who_viewed_me_days_bonus')) {
            Schema::table('referral_reward_rules', function (Blueprint $table) {
                $table->dropColumn('who_viewed_me_days_bonus');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('referral_reward_rules') && ! Schema::hasColumn('referral_reward_rules', 'who_viewed_me_days_bonus')) {
            Schema::table('referral_reward_rules', function (Blueprint $table) {
                $table->unsignedInteger('who_viewed_me_days_bonus')->default(0);
            });
        }
    }
};
