<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Profile Field Configuration Table (SSOT Day 5-6)
|--------------------------------------------------------------------------
|
| Database-backed field settings for profile fields.
| Foundation only — no business logic wiring in this migration.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_field_configs', function (Blueprint $table) {
            $table->id();
            $table->string('field_key')->unique();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_mandatory')->default(false);
            $table->timestamps();
        });

        // Seed initial mandatory fields
        DB::table('profile_field_configs')->insert([
            [
                'field_key' => 'gender',
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'is_mandatory' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'field_key' => 'date_of_birth',
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'is_mandatory' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'field_key' => 'marital_status',
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'is_mandatory' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'field_key' => 'education',
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'is_mandatory' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'field_key' => 'location',
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'is_mandatory' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'field_key' => 'profile_photo',
                'is_enabled' => true,
                'is_visible' => true,
                'is_searchable' => false,
                'is_mandatory' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_field_configs');
    }
};
