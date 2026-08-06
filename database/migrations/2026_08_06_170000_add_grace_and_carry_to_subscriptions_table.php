<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Checkpoint A: freeze grace/carry on the subscription contract row.
 * Catalog remains {@see plans.grace_period_days} / {@see plans.leftover_quota_carry_window_days}.
 * Readers still use plans until Checkpoint B.
 *
 * Idempotent: hasTable/hasColumn guards — never adds duplicate columns.
 * Backfill UPDATEs existing subscription rows only — never inserts subscriptions or plan_terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'grace_period_days')) {
                $table->unsignedSmallInteger('grace_period_days')->default(0)->after('status');
            }
            if (! Schema::hasColumn('subscriptions', 'leftover_quota_carry_window_days')) {
                $table->unsignedSmallInteger('leftover_quota_carry_window_days')->nullable()->after('grace_period_days');
            }
        });

        if (! Schema::hasTable('plans')
            || ! Schema::hasColumn('subscriptions', 'grace_period_days')
            || ! Schema::hasColumn('subscriptions', 'leftover_quota_carry_window_days')
        ) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'UPDATE subscriptions s
                INNER JOIN plans p ON p.id = s.plan_id
                SET s.grace_period_days = COALESCE(p.grace_period_days, 0),
                    s.leftover_quota_carry_window_days = p.leftover_quota_carry_window_days'
            );
        } else {
            $rows = DB::table('subscriptions as s')
                ->join('plans as p', 'p.id', '=', 's.plan_id')
                ->select([
                    's.id',
                    'p.grace_period_days',
                    'p.leftover_quota_carry_window_days',
                ])
                ->get();
            foreach ($rows as $row) {
                DB::table('subscriptions')->where('id', $row->id)->update([
                    'grace_period_days' => max(0, (int) ($row->grace_period_days ?? 0)),
                    'leftover_quota_carry_window_days' => $row->leftover_quota_carry_window_days === null
                        ? null
                        : max(0, (int) $row->leftover_quota_carry_window_days),
                ]);
            }
        }

        // Rows without a joinable plan (should be rare): keep default zero grace.
        DB::table('subscriptions')->whereNull('grace_period_days')->update(['grace_period_days' => 0]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'leftover_quota_carry_window_days')) {
                $table->dropColumn('leftover_quota_carry_window_days');
            }
            if (Schema::hasColumn('subscriptions', 'grace_period_days')) {
                $table->dropColumn('grace_period_days');
            }
        });
    }
};
