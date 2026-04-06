<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical quota storage: {@see \App\Models\UserFeatureUsage} → {@code user_feature_usages}.
     * Drops legacy composite index when present; copies rows from {@code user_feature_usage};
     * adds per-column indexes and bucket unique key when the old composite was removed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_feature_usages')) {
            return;
        }

        $droppedComposite = $this->dropLegacyCompositeIndexIfPresent();

        if (Schema::hasTable('user_feature_usage') && DB::table('user_feature_usage')->exists()) {
            DB::table('user_feature_usages')->delete();

            foreach (DB::table('user_feature_usage')->orderBy('id')->cursor() as $row) {
                $start = Carbon::parse($row->period_start)->startOfDay();
                $endRaw = $row->period_end ?? null;
                if ($endRaw === null || $endRaw === '') {
                    $end = (($row->period ?? '') === 'daily')
                        ? $start->copy()
                        : $start->copy()->endOfMonth()->startOfDay();
                } else {
                    $end = Carbon::parse($endRaw)->startOfDay();
                }

                DB::table('user_feature_usages')->insert([
                    'user_id' => $row->user_id,
                    'feature_key' => $row->feature_key,
                    'used_count' => (int) $row->used_count,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        }

        if ($droppedComposite) {
            $this->ensureIndexesAndUnique();
        }
    }

    public function down(): void
    {
        // Cutover is not reversed (legacy table retained).
    }

    private function dropLegacyCompositeIndexIfPresent(): bool
    {
        try {
            Schema::table('user_feature_usages', function (Blueprint $table) {
                $table->dropIndex('user_feature_usages_user_feature_idx');
            });

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureIndexesAndUnique(): void
    {
        try {
            Schema::table('user_feature_usages', function (Blueprint $table) {
                $table->index('user_id', 'user_feature_usages_user_id_idx');
            });
        } catch (\Throwable) {
        }
        try {
            Schema::table('user_feature_usages', function (Blueprint $table) {
                $table->index('feature_key', 'user_feature_usages_feature_key_idx');
            });
        } catch (\Throwable) {
        }
        try {
            Schema::table('user_feature_usages', function (Blueprint $table) {
                $table->unique(
                    ['user_id', 'feature_key', 'period_start', 'period_end'],
                    'user_feature_usages_bucket_uq'
                );
            });
        } catch (\Throwable) {
        }
    }
};
