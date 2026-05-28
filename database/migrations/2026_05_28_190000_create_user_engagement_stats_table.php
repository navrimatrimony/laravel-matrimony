<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_engagement_stats')) {
            return;
        }

        Schema::create('user_engagement_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ads_viewed_count')->default(0);
            $table->unsignedInteger('referrals_done')->default(0);
            $table->unsignedInteger('profiles_completed')->default(0);
            $table->unsignedInteger('daily_login_streak')->default(0);
            $table->unsignedInteger('unlock_credits_available')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_engagement_stats');
    }
};
