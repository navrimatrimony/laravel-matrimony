<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth: canonical residence is location_id; legacy city_id must not carry parallel truth.
 * Column retained (PHASE-5); values cleared when location_id is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('matrimony_profiles')
            && Schema::hasColumn('matrimony_profiles', 'city_id')
            && Schema::hasColumn('matrimony_profiles', 'location_id')) {
            DB::table('matrimony_profiles')
                ->whereNotNull('location_id')
                ->update(['city_id' => null]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: cannot safely reconstruct legacy city_id.
    }
};
