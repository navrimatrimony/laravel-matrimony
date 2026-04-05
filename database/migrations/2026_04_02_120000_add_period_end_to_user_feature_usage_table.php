<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds period_end (inclusive bucket end) for clearer monthly windows.
     * Canonical table remains user_feature_usage (existing SSOT); not renamed per Phase-5.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_feature_usage')) {
            return;
        }

        if (! Schema::hasColumn('user_feature_usage', 'period_end')) {
            Schema::table('user_feature_usage', function (Blueprint $table) {
                $table->date('period_end')->nullable()->after('period_start');
            });
        }

        DB::table('user_feature_usage')
            ->whereNull('period_end')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $start = Carbon::parse($row->period_start);
                    $end = ($row->period ?? '') === 'daily'
                        ? $start->copy()->toDateString()
                        : $start->copy()->endOfMonth()->toDateString();
                    DB::table('user_feature_usage')->where('id', $row->id)->update(['period_end' => $end]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_feature_usage')) {
            return;
        }

        if (Schema::hasColumn('user_feature_usage', 'period_end')) {
            Schema::table('user_feature_usage', function (Blueprint $table) {
                $table->dropColumn('period_end');
            });
        }
    }
};
