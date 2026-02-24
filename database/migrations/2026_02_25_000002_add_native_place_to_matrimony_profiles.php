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
            if (! Schema::hasColumn('matrimony_profiles', 'native_city_id')) {
                $table->foreignId('native_city_id')->nullable()->after('work_state_id')->constrained('cities')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'native_taluka_id')) {
                $table->foreignId('native_taluka_id')->nullable()->after('native_city_id')->constrained('talukas')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'native_district_id')) {
                $table->foreignId('native_district_id')->nullable()->after('native_taluka_id')->constrained('districts')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'native_state_id')) {
                $table->foreignId('native_state_id')->nullable()->after('native_district_id')->constrained('states')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'native_state_id')) {
                $table->dropForeign(['native_state_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'native_district_id')) {
                $table->dropForeign(['native_district_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'native_taluka_id')) {
                $table->dropForeign(['native_taluka_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'native_city_id')) {
                $table->dropForeign(['native_city_id']);
            }
        });
    }
};
