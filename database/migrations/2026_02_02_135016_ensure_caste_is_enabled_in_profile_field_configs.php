<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Ensure Caste is Enabled in Profile Field Configs (SSOT Day-11)
|--------------------------------------------------------------------------
|
| Ensures mandatory CORE field 'caste' is enabled for completeness calculation.
| Metadata fix only — no schema changes.
|
*/
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('profile_field_configs')) {
            return;
        }

        // Ensure caste config exists and is enabled
        DB::table('profile_field_configs')->updateOrInsert(
            ['field_key' => 'caste'],
            [
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'is_mandatory' => true,
                'updated_at' => now(),
            ]
        );

        // STEP 2: Verify inputs — caste MUST appear in 'used'
        \Log::info('VERIFY_INPUTS', [
            'mandatory' => \App\Models\FieldRegistry::where('field_type', 'CORE')->where('is_mandatory', true)->pluck('field_key')->values(),
            'enabled' => \App\Services\ProfileFieldConfigurationService::getEnabledFieldKeys(),
            'used' => array_values(array_intersect(
                \App\Models\FieldRegistry::where('field_type', 'CORE')->where('is_mandatory', true)->pluck('field_key')->toArray(),
                \App\Services\ProfileFieldConfigurationService::getEnabledFieldKeys()
            )),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback needed — this is a data fix, not schema change
    }
};
