<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referral_reward_rules')) {
            return;
        }

        Schema::create('referral_reward_rules', function (Blueprint $table) {
            $table->id();
            $table->string('plan_slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('bonus_days')->default(0);
            $table->unsignedInteger('chat_send_limit_bonus')->default(0);
            $table->unsignedInteger('contact_view_limit_bonus')->default(0);
            $table->unsignedInteger('interest_send_limit_bonus')->default(0);
            $table->unsignedInteger('daily_profile_view_limit_bonus')->default(0);
            $table->unsignedInteger('who_viewed_me_days_bonus')->default(0);
            $table->unsignedInteger('who_viewed_me_preview_limit_bonus')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_reward_rules');
    }
};

