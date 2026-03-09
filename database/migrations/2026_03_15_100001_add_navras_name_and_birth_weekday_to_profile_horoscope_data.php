<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_horoscope_data', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_horoscope_data', 'navras_name')) {
                $table->string('navras_name')->nullable()->after('gotra');
            }
            if (! Schema::hasColumn('profile_horoscope_data', 'birth_weekday')) {
                $table->string('birth_weekday')->nullable()->after('navras_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_horoscope_data', function (Blueprint $table) {
            if (Schema::hasColumn('profile_horoscope_data', 'birth_weekday')) {
                $table->dropColumn('birth_weekday');
            }
            if (Schema::hasColumn('profile_horoscope_data', 'navras_name')) {
                $table->dropColumn('navras_name');
            }
        });
    }
};

