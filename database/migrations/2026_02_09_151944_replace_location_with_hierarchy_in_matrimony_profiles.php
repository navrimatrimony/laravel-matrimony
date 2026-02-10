<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase-4 Day-8: Replace free-text location with hierarchical foreign keys
     * 
     * BREAKING CHANGE: Removes location (string) column
     * Data migration strategy must be executed separately before running this migration
     */
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            // Add hierarchical location foreign keys
            $table->foreignId('country_id')->nullable()->after('location')->constrained('countries')->onDelete('restrict');
            $table->foreignId('state_id')->nullable()->after('country_id')->constrained('states')->onDelete('restrict');
            $table->foreignId('district_id')->nullable()->after('state_id')->constrained('districts')->onDelete('restrict');
            $table->foreignId('taluka_id')->nullable()->after('district_id')->constrained('talukas')->onDelete('restrict');
            $table->foreignId('city_id')->nullable()->after('taluka_id')->constrained('cities')->onDelete('restrict');
            
            // Remove free-text location column
            $table->dropColumn('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            // Restore free-text location column
            $table->string('location')->nullable()->after('education');
            
            // Drop hierarchical location foreign keys
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            $table->dropForeign(['district_id']);
            $table->dropForeign(['taluka_id']);
            $table->dropForeign(['city_id']);
            
            $table->dropColumn(['country_id', 'state_id', 'district_id', 'taluka_id', 'city_id']);
        });
    }
};
