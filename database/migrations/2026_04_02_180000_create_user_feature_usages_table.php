<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user quota buckets for feature limits (used_count within [period_start, period_end]).
     */
    public function up(): void
    {
        if (Schema::hasTable('user_feature_usages')) {
            return;
        }

        Schema::create('user_feature_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('feature_key', 120);
            $table->unsignedInteger('used_count')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();

            $table->index('user_id', 'user_feature_usages_user_id_idx');
            $table->index('feature_key', 'user_feature_usages_feature_key_idx');
            $table->unique(
                ['user_id', 'feature_key', 'period_start', 'period_end'],
                'user_feature_usages_bucket_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feature_usages');
    }
};
