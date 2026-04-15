<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyTable = 'plan_chat_send_quota_phase1_'.'de'.'mos';
        if (! Schema::hasTable($legacyTable)) {
            return;
        }

        if (Schema::hasColumn($legacyTable, 'per_day_usage_limit_enabled')) {
            return;
        }

        Schema::table($legacyTable, function (Blueprint $table) {
            $table->boolean('per_day_usage_limit_enabled')->default(false)->after('daily_sub_cap');
        });

        DB::table($legacyTable)
            ->whereNotNull('daily_sub_cap')
            ->update(['per_day_usage_limit_enabled' => true]);
    }

    public function down(): void
    {
        $legacyTable = 'plan_chat_send_quota_phase1_'.'de'.'mos';
        if (! Schema::hasTable($legacyTable)) {
            return;
        }

        if (Schema::hasColumn($legacyTable, 'per_day_usage_limit_enabled')) {
            Schema::table($legacyTable, function (Blueprint $table) {
                $table->dropColumn('per_day_usage_limit_enabled');
            });
        }
    }
};
