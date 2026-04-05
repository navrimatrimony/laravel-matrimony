<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks per-user usage of quota-style features (e.g. contact reveals, mediator requests).
     * Rows are bucketed by period (e.g. monthly) via period_start so counts reset each bucket without cron.
     */
    public function up(): void
    {
        Schema::create('user_feature_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('feature_key', 80);
            $table->unsignedInteger('used_count')->default(0);
            /** @see \App\Models\UserFeatureUsage::PERIOD_MONTHLY */
            $table->string('period', 32);
            /** Start of the bucket (e.g. first day of month when period = monthly). */
            $table->date('period_start');
            $table->timestamps();

            $table->unique(
                ['user_id', 'feature_key', 'period', 'period_start'],
                'user_feature_usage_user_feature_period_bucket_uq'
            );
            $table->index(['user_id', 'period', 'period_start'], 'user_feature_usage_user_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feature_usage');
    }
};
