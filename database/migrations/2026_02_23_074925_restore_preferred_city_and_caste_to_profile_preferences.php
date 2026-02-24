<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RestorePreferredCityAndCasteToProfilePreferences extends Migration
{
    public function up(): void
    {
        Schema::table('profile_preferences', function (Blueprint $table) {

            if (!Schema::hasColumn('profile_preferences', 'preferred_city')) {
                $table->string('preferred_city')->nullable()->after('profile_id');
            }

            if (!Schema::hasColumn('profile_preferences', 'preferred_caste')) {
                $table->string('preferred_caste')->nullable()->after('preferred_city');
            }

        });
    }

    public function down(): void
    {
        Schema::table('profile_preferences', function (Blueprint $table) {

            if (Schema::hasColumn('profile_preferences', 'preferred_city')) {
                $table->dropColumn('preferred_city');
            }

            if (Schema::hasColumn('profile_preferences', 'preferred_caste')) {
                $table->dropColumn('preferred_caste');
            }

        });
    }
}