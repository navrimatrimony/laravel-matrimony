<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plan_chat_send_quota_phase1_demos')) {
            return;
        }

        if (Schema::hasColumn('plan_chat_send_quota_phase1_demos', 'per_day_usage_limit_enabled')) {
            return;
        }

        Schema::table('plan_chat_send_quota_phase1_demos', function (Blueprint $table) {
            $table->boolean('per_day_usage_limit_enabled')->default(false)->after('daily_sub_cap');
        });

        DB::table('plan_chat_send_quota_phase1_demos')
            ->whereNotNull('daily_sub_cap')
            ->update(['per_day_usage_limit_enabled' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('plan_chat_send_quota_phase1_demos')) {
            return;
        }

        if (Schema::hasColumn('plan_chat_send_quota_phase1_demos', 'per_day_usage_limit_enabled')) {
            Schema::table('plan_chat_send_quota_phase1_demos', function (Blueprint $table) {
                $table->dropColumn('per_day_usage_limit_enabled');
            });
        }
    }
};
