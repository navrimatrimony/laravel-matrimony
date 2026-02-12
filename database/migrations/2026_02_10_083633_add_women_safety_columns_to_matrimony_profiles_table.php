<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase-4 Day-10: Women-First Safety Governance Foundation
     */
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            // Profile visibility control (who can view profile)
            $table->string('profile_visibility_mode', 50)->nullable()->after('visibility_override_reason');
            
            // Contact detail storage and visibility
            $table->string('contact_number', 20)->nullable()->after('profile_visibility_mode');
            $table->json('contact_visible_to')->nullable()->after('contact_number');
            $table->string('contact_unlock_mode', 50)->nullable()->after('contact_visible_to');
            
            // Safety defaults tracking
            $table->boolean('safety_defaults_applied')->default(false)->after('contact_unlock_mode');
        });

        // Safe backfill: Apply gender-based defaults to existing records
        DB::statement("
            UPDATE matrimony_profiles 
            SET 
                profile_visibility_mode = CASE 
                    WHEN gender = 'female' THEN 'verified_only'
                    WHEN gender = 'male' THEN 'public'
                    ELSE 'public'
                END,
                contact_unlock_mode = 'after_interest_accepted',
                safety_defaults_applied = true
            WHERE safety_defaults_applied = false OR safety_defaults_applied IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'profile_visibility_mode',
                'contact_number',
                'contact_visible_to',
                'contact_unlock_mode',
                'safety_defaults_applied',
            ]);
        });
    }
};
