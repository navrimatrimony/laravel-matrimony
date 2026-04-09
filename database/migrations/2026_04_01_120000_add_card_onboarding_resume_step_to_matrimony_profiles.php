<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Card onboarding (steps 2–4) + photo handoff: nullable resume pointer.
     * 2–7 = card step; 8 = photo upload phase; null = not in forced onboarding.
     */
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('card_onboarding_resume_step')->nullable()->after('lifecycle_state');
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn('card_onboarding_resume_step');
        });
    }
};
