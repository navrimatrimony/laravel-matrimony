<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_referrals')) {
            return;
        }

        Schema::table('user_referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('user_referrals', 'reward_status')) {
                $table->string('reward_status', 32)->nullable()->after('reward_applied');
            }
            if (! Schema::hasColumn('user_referrals', 'pending_plan_id')) {
                $table->foreignId('pending_plan_id')->nullable()->after('reward_status')->constrained('plans')->nullOnDelete();
            }
            if (! Schema::hasColumn('user_referrals', 'pending_reward')) {
                $table->json('pending_reward')->nullable()->after('pending_plan_id');
            }
        });

        DB::table('user_referrals')
            ->where('reward_applied', true)
            ->whereNull('reward_status')
            ->update(['reward_status' => 'applied']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_referrals')) {
            return;
        }

        Schema::table('user_referrals', function (Blueprint $table) {
            if (Schema::hasColumn('user_referrals', 'pending_plan_id')) {
                $table->dropConstrainedForeignId('pending_plan_id');
            }
            if (Schema::hasColumn('user_referrals', 'pending_reward')) {
                $table->dropColumn('pending_reward');
            }
            if (Schema::hasColumn('user_referrals', 'reward_status')) {
                $table->dropColumn('reward_status');
            }
        });
    }
};
