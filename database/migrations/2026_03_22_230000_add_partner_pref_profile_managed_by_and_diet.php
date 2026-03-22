<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner preferences: who manages partner's profile (same enum as registration) + diet multi-select (master_diets).
 */
return new class extends Migration
{
    public function up(): void
    {
        $criteria = 'profile_preference_criteria';
        if (Schema::hasTable($criteria) && ! Schema::hasColumn($criteria, 'preferred_profile_managed_by')) {
            Schema::table($criteria, function (Blueprint $table) use ($criteria) {
                if (Schema::hasColumn($criteria, 'partner_profile_with_children')) {
                    $table->string('preferred_profile_managed_by', 32)->nullable()->after('partner_profile_with_children');
                } else {
                    $table->string('preferred_profile_managed_by', 32)->nullable();
                }
            });
        }

        if (! Schema::hasTable('profile_preferred_diets')) {
            Schema::create('profile_preferred_diets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
                $table->foreignId('diet_id')->constrained('master_diets')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['profile_id', 'diet_id'], 'ppd_profile_diet_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_preferred_diets');
        $criteria = 'profile_preference_criteria';
        if (Schema::hasTable($criteria) && Schema::hasColumn($criteria, 'preferred_profile_managed_by')) {
            Schema::table($criteria, function (Blueprint $table) {
                $table->dropColumn('preferred_profile_managed_by');
            });
        }
    }
};
