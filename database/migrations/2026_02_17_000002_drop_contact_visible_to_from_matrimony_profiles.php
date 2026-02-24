<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: Remove JSON column contact_visible_to after migration to profile_contact_visibility.
 * Run after 2026_02_17_000001_create_profile_contact_visibility_table (with backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn('contact_visible_to');
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->json('contact_visible_to')->nullable()->after('contact_number');
        });
    }
};
