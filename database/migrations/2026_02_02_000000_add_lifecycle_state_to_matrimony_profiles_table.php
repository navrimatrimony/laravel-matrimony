<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-3 Day 7: Add canonical lifecycle_state to matrimony_profiles.
 * Governance-only; does NOT replace is_suspended, deleted_at, is_demo, visibility_override.
 * Default ACTIVE = safe for existing profiles (preserves current visibility).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->string('lifecycle_state', 32)->default('Active')->after('visibility_override_reason');
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn('lifecycle_state');
        });
    }
};
