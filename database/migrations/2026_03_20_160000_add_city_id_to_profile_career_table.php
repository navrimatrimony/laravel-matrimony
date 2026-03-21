<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_career') || Schema::hasColumn('profile_career', 'city_id')) {
            return;
        }
        Schema::table('profile_career', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_career') || ! Schema::hasColumn('profile_career', 'city_id')) {
            return;
        }
        Schema::table('profile_career', function (Blueprint $table) {
            $table->dropColumn('city_id');
        });
    }
};
