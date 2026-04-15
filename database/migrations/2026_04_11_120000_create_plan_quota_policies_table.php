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
            return;
        }

        Schema::create('plan_quota_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key', 64);

            $table->boolean('is_enabled')->default(true);
            $table->string('refresh_type', 32)->default('monthly_30d_ist');
            $table->unsignedInteger('limit_value')->nullable();
            $table->unsignedInteger('daily_sub_cap')->nullable();
            $table->boolean('per_day_usage_limit_enabled')->default(false);
            $table->unsignedTinyInteger('grace_percent_of_plan')->default(10);
            $table->string('overuse_mode', 16)->default('block');
            $table->unsignedInteger('pack_price_paise')->nullable();
            $table->unsignedInteger('pack_message_count')->nullable();
            $table->unsignedSmallInteger('pack_validity_days')->nullable();
            $table->json('policy_meta')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });

        $legacyTable = 'plan_chat_send_quota_phase1_'.'de'.'mos';
        if (Schema::hasTable($legacyTable)) {
            $hasPerDay = Schema::hasColumn($legacyTable, 'per_day_usage_limit_enabled');
            $rows = DB::table($legacyTable)->get();
            foreach ($rows as $row) {
                $insert = [
                    'plan_id' => $row->plan_id,
                    'feature_key' => 'chat_send_limit',
                    'is_enabled' => (bool) $row->is_enabled,
                    'refresh_type' => (string) $row->refresh_type,
                    'limit_value' => $row->limit_value,
                    'daily_sub_cap' => $row->daily_sub_cap,
                    'per_day_usage_limit_enabled' => $hasPerDay ? (bool) ($row->per_day_usage_limit_enabled ?? false) : false,
                    'grace_percent_of_plan' => (int) $row->grace_percent_of_plan,
                    'overuse_mode' => (string) $row->overuse_mode,
                    'pack_price_paise' => $row->pack_price_paise,
                    'pack_message_count' => $row->pack_message_count,
                    'pack_validity_days' => $row->pack_validity_days,
                    'policy_meta' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                DB::table('plan_quota_policies')->insert($insert);
            }
            Schema::dropIfExists($legacyTable);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_quota_policies');
    }
};
