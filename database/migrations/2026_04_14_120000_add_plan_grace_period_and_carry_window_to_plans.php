<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'grace_period_days')) {
                $table->unsignedSmallInteger('grace_period_days')->default(0)->after('duration_days');
            }
            if (! Schema::hasColumn('plans', 'leftover_quota_carry_window_days')) {
                $table->unsignedSmallInteger('leftover_quota_carry_window_days')->nullable()->after('grace_period_days');
            }
        });

        // Backfill grace_period_days from first quota policy row (legacy per-policy % × plan duration).
        if (Schema::hasTable('plan_quota_policies')) {
            $plans = DB::table('plans')->select('id', 'duration_days')->get();
            foreach ($plans as $plan) {
                $dur = (int) ($plan->duration_days ?? 0);
                if ($dur <= 0) {
                    continue;
                }
                $row = DB::table('plan_quota_policies')
                    ->where('plan_id', $plan->id)
                    ->orderBy('id')
                    ->first();
                if (! $row) {
                    continue;
                }
                $pct = (int) ($row->grace_percent_of_plan ?? 0);
                $days = (int) max(0, min(65535, round($pct / 100 * $dur)));
                DB::table('plans')->where('id', $plan->id)->update(['grace_period_days' => $days]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'leftover_quota_carry_window_days')) {
                $table->dropColumn('leftover_quota_carry_window_days');
            }
            if (Schema::hasColumn('plans', 'grace_period_days')) {
                $table->dropColumn('grace_period_days');
            }
        });
    }
};
