<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('matrimony_profiles', 'birth_city_id')) {
                $table->foreignId('birth_city_id')->nullable()->after('city_id')->constrained('cities')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'birth_taluka_id')) {
                $table->foreignId('birth_taluka_id')->nullable()->after('birth_city_id')->constrained('talukas')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'birth_district_id')) {
                $table->foreignId('birth_district_id')->nullable()->after('birth_taluka_id')->constrained('districts')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'birth_state_id')) {
                $table->foreignId('birth_state_id')->nullable()->after('birth_district_id')->constrained('states')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'birth_state_id')) {
                $table->dropForeign(['birth_state_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'birth_district_id')) {
                $table->dropForeign(['birth_district_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'birth_taluka_id')) {
                $table->dropForeign(['birth_taluka_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'birth_city_id')) {
                $table->dropForeign(['birth_city_id']);
            }
        });
    }
};
