<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Add Missing Profile Field Configs (SSOT Day-13)
|--------------------------------------------------------------------------
|
| Seeds caste and height_cm field configs for searchable flag enforcement.
| No schema changes — data insert only.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('profile_field_configs')) {
            return;
        }

        // Insert caste config if not exists
        if (!DB::table('profile_field_configs')->where('field_key', 'caste')->exists()) {
            DB::table('profile_field_configs')->insert([
                'field_key' => 'caste',
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'is_mandatory' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Insert height_cm config if not exists
        if (!DB::table('profile_field_configs')->where('field_key', 'height_cm')->exists()) {
            DB::table('profile_field_configs')->insert([
                'field_key' => 'height_cm',
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('profile_field_configs')) {
            return;
        }
        DB::table('profile_field_configs')->whereIn('field_key', ['caste', 'height_cm'])->delete();
    }
};
