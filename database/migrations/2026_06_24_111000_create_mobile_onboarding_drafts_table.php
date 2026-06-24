<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_onboarding_drafts')) {
            return;
        }

        Schema::create('mobile_onboarding_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('matrimony_profile_id')->nullable()->constrained('matrimony_profiles')->nullOnDelete();
            $table->string('current_step', 64)->nullable()->index();
            $table->string('last_completed_step', 64)->nullable()->index();
            $table->json('draft_data')->nullable();
            $table->json('completed_steps')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('matrimony_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_onboarding_drafts');
    }
};
