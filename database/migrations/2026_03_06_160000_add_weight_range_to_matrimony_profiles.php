<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add weight_range for user-friendly range dropdown (PHASE-5 additive only).
 * Values: below_40, 40_50, 50_60, 60_70, 70_80, 80_90, 90_plus
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('matrimony_profiles', 'weight_range')) {
                $table->string('weight_range', 20)->nullable()->after('weight_kg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'weight_range')) {
                $table->dropColumn('weight_range');
            }
        });
    }
};
