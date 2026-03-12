<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 additive: Add mother_tongue_id, diet_id, smoking_status_id, drinking_status_id to matrimony_profiles;
 * willing_to_relocate, settled_city_preference_id, marriage_type_preference_id to profile_preference_criteria;
 * mangal_status_id to profile_horoscope_data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $profiles = 'matrimony_profiles';
        if (Schema::hasTable($profiles)) {
            Schema::table($profiles, function (Blueprint $table) use ($profiles) {
                if (! Schema::hasColumn($profiles, 'mother_tongue_id')) {
                    $table->foreignId('mother_tongue_id')->nullable()->after('sub_caste_id')->constrained('master_mother_tongues')->nullOnDelete();
                }
                if (! Schema::hasColumn($profiles, 'diet_id')) {
                    $table->foreignId('diet_id')->nullable()->after('physical_condition')->constrained('master_diets')->nullOnDelete();
                }
                if (! Schema::hasColumn($profiles, 'smoking_status_id')) {
                    $table->foreignId('smoking_status_id')->nullable()->after('diet_id')->constrained('master_smoking_statuses')->nullOnDelete();
                }
                if (! Schema::hasColumn($profiles, 'drinking_status_id')) {
                    $table->foreignId('drinking_status_id')->nullable()->after('smoking_status_id')->constrained('master_drinking_statuses')->nullOnDelete();
                }
            });
        }

        $criteria = 'profile_preference_criteria';
        if (Schema::hasTable($criteria)) {
            Schema::table($criteria, function (Blueprint $table) use ($criteria) {
                if (! Schema::hasColumn($criteria, 'willing_to_relocate')) {
                    $table->boolean('willing_to_relocate')->nullable()->after('preferred_city_id');
                }
                if (! Schema::hasColumn($criteria, 'settled_city_preference_id')) {
                    $table->foreignId('settled_city_preference_id')->nullable()->after('willing_to_relocate')->constrained('cities')->nullOnDelete();
                }
                if (! Schema::hasColumn($criteria, 'marriage_type_preference_id')) {
                    $table->foreignId('marriage_type_preference_id')->nullable()->after('settled_city_preference_id')->constrained('master_marriage_type_preferences')->nullOnDelete();
                }
            });
        }

        $horoscope = 'profile_horoscope_data';
        if (Schema::hasTable($horoscope)) {
            Schema::table($horoscope, function (Blueprint $table) use ($horoscope) {
                if (! Schema::hasColumn($horoscope, 'mangal_status_id')) {
                    $table->foreignId('mangal_status_id')->nullable()->after('mangal_dosh_type_id')->constrained('master_mangal_statuses')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        $horoscope = 'profile_horoscope_data';
        if (Schema::hasTable($horoscope) && Schema::hasColumn($horoscope, 'mangal_status_id')) {
            Schema::table($horoscope, function (Blueprint $table) {
                $table->dropForeign(['mangal_status_id']);
            });
        }

        $criteria = 'profile_preference_criteria';
        if (Schema::hasTable($criteria)) {
            Schema::table($criteria, function (Blueprint $table) use ($criteria) {
                if (Schema::hasColumn($criteria, 'marriage_type_preference_id')) {
                    $table->dropForeign(['marriage_type_preference_id']);
                }
                if (Schema::hasColumn($criteria, 'settled_city_preference_id')) {
                    $table->dropForeign(['settled_city_preference_id']);
                }
                if (Schema::hasColumn($criteria, 'willing_to_relocate')) {
                    $table->dropColumn('willing_to_relocate');
                }
            });
        }

        $profiles = 'matrimony_profiles';
        if (Schema::hasTable($profiles)) {
            Schema::table($profiles, function (Blueprint $table) use ($profiles) {
                if (Schema::hasColumn($profiles, 'drinking_status_id')) {
                    $table->dropForeign(['drinking_status_id']);
                }
                if (Schema::hasColumn($profiles, 'smoking_status_id')) {
                    $table->dropForeign(['smoking_status_id']);
                }
                if (Schema::hasColumn($profiles, 'diet_id')) {
                    $table->dropForeign(['diet_id']);
                }
                if (Schema::hasColumn($profiles, 'mother_tongue_id')) {
                    $table->dropForeign(['mother_tongue_id']);
                }
            });
        }
    }
};
