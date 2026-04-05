<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinct profiles per calendar day for free users without an approved photo (photo access cap).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_daily_photo_profile_views')) {
            return;
        }

        Schema::create('user_daily_photo_profile_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('viewed_profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->date('viewed_on');
            $table->timestamps();

            $table->unique(
                ['user_id', 'viewed_profile_id', 'viewed_on'],
                'udppv_user_profile_day_uq'
            );
            $table->index(['user_id', 'viewed_on'], 'udppv_user_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_daily_photo_profile_views');
    }
};
