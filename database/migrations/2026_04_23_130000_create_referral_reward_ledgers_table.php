<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referral_reward_ledgers')) {
            return;
        }

        Schema::create('referral_reward_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_referral_id')->nullable()->constrained('user_referrals')->nullOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('performed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_type', 40);
            $table->unsignedInteger('bonus_days')->default(0);
            $table->json('feature_bonus')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'created_at']);
            $table->index(['action_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_reward_ledgers');
    }
};

