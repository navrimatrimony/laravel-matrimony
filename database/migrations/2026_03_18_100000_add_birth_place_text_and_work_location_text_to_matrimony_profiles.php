<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: birth_place_text and work_location_text for display when location IDs are missing.
 * So Full profile wizard shows intake text for Birth place and Work location even without city lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('matrimony_profiles', 'birth_place_text')) {
                $table->string('birth_place_text', 500)->nullable()->after('birth_state_id');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'work_location_text')) {
                $table->string('work_location_text', 255)->nullable()->after('company_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'birth_place_text')) {
                $table->dropColumn('birth_place_text');
            }
            if (Schema::hasColumn('matrimony_profiles', 'work_location_text')) {
                $table->dropColumn('work_location_text');
            }
        });
    }
};
