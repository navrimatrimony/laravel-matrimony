<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $name = 'profile_preference_criteria';
        if (! Schema::hasTable($name)) {
            return;
        }
        Schema::table($name, function (Blueprint $table) use ($name) {
            if (! Schema::hasColumn($name, 'preferred_height_min_cm')) {
                $table->unsignedSmallInteger('preferred_height_min_cm')->nullable();
            }
            if (! Schema::hasColumn($name, 'preferred_height_max_cm')) {
                $table->unsignedSmallInteger('preferred_height_max_cm')->nullable();
            }
        });
    }

    public function down(): void
    {
        $name = 'profile_preference_criteria';
        if (! Schema::hasTable($name)) {
            return;
        }
        Schema::table($name, function (Blueprint $table) use ($name) {
            if (Schema::hasColumn($name, 'preferred_height_max_cm')) {
                $table->dropColumn('preferred_height_max_cm');
            }
            if (Schema::hasColumn($name, 'preferred_height_min_cm')) {
                $table->dropColumn('preferred_height_min_cm');
            }
        });
    }
};
