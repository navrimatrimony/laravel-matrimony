<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERIOD_DAILY = 'daily';

    private const PERIOD_MONTHLY = 'monthly';

    private const PERIOD_ENTITLEMENT = 'entitlement';

    /**
     * Replace incorrect unique (user_id, feature_key, period_start, period_end) with
     * bucket identity (user_id, feature_key, period, period_start). Adds {@code period} when missing.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_feature_usages')) {
            return;
        }

        if (! Schema::hasColumn('user_feature_usages', 'period')) {
            Schema::table('user_feature_usages', function (Blueprint $table) {
                $table->string('period', 32)->nullable()->after('feature_key');
            });
        }

        $this->backfillPeriodWhereNull();

        if (DB::table('user_feature_usages')->whereNull('period')->exists()) {
            throw new RuntimeException(
                'user_feature_usages: cannot fix unique index — period backfill left NULL rows.'
            );
        }

        $this->assertNoDuplicateBuckets();

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE user_feature_usages MODIFY period VARCHAR(32) NOT NULL');
        }

        $this->dropBucketUniqueIfPresent();

        Schema::table('user_feature_usages', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'feature_key', 'period', 'period_start'],
                'user_feature_usages_bucket_uq'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_feature_usages') || ! Schema::hasColumn('user_feature_usages', 'period')) {
            return;
        }

        $this->dropBucketUniqueIfPresent();

        Schema::table('user_feature_usages', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'feature_key', 'period_start', 'period_end'],
                'user_feature_usages_bucket_uq'
            );
        });

        Schema::table('user_feature_usages', function (Blueprint $table) {
            $table->dropColumn('period');
        });
    }

    private function backfillPeriodWhereNull(): void
    {
        DB::table('user_feature_usages')
            ->whereNull('period')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $start = Carbon::parse($row->period_start)->startOfDay();
                    $end = Carbon::parse($row->period_end)->startOfDay();
                    $period = $this->inferPeriod($start, $end);
                    DB::table('user_feature_usages')->where('id', $row->id)->update(['period' => $period]);
                }
            });
    }

    private function inferPeriod(Carbon $start, Carbon $end): string
    {
        if ($start->equalTo($end)) {
            return self::PERIOD_DAILY;
        }
        if ($start->day === 1 && $end->equalTo($start->copy()->endOfMonth()->startOfDay())) {
            return self::PERIOD_MONTHLY;
        }

        return self::PERIOD_ENTITLEMENT;
    }

    private function assertNoDuplicateBuckets(): void
    {
        $dup = DB::table('user_feature_usages')
            ->select('user_id', 'feature_key', 'period', 'period_start')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('user_id', 'feature_key', 'period', 'period_start')
            ->having('c', '>', 1)
            ->first();

        if ($dup !== null) {
            throw new RuntimeException(
                'user_feature_usages: duplicate buckets for (user_id, feature_key, period, period_start); resolve manually before migrating.'
            );
        }
    }

    private function dropBucketUniqueIfPresent(): void
    {
        try {
            Schema::table('user_feature_usages', function (Blueprint $table) {
                $table->dropUnique('user_feature_usages_bucket_uq');
            });
        } catch (\Throwable $e) {
            // Index may already be dropped or renamed in a partial deploy.
        }
    }
};
