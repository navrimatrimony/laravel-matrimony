<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy singular table superseded by {@code user_feature_usages}.
     * Safe when {@code user_feature_usage} has no rows (data cut over in 2026_04_05_100000).
     */
    public function up(): void
    {
        Schema::dropIfExists('user_feature_usage');
    }

    public function down(): void
    {
        if (Schema::hasTable('user_feature_usage')) {
            return;
        }

        Schema::create('user_feature_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('feature_key', 80);
            $table->unsignedInteger('used_count')->default(0);
            $table->string('period', 32);
            $table->date('period_start');
            $table->date('period_end')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'feature_key', 'period', 'period_start'],
                'user_feature_usage_user_feature_period_bucket_uq'
            );
            $table->index(['user_id', 'period', 'period_start'], 'user_feature_usage_user_period_idx');
        });
    }
};
