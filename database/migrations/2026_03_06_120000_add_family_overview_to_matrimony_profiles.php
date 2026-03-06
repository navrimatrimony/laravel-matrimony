<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('matrimony_profiles', 'family_status')) {
                $table->string('family_status')->nullable()->after('family_type_id');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_values')) {
                $table->string('family_values')->nullable()->after('family_status');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_annual_income')) {
                $table->string('family_annual_income')->nullable()->after('family_values');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'family_annual_income')) {
                $table->dropColumn('family_annual_income');
            }
            if (Schema::hasColumn('matrimony_profiles', 'family_values')) {
                $table->dropColumn('family_values');
            }
            if (Schema::hasColumn('matrimony_profiles', 'family_status')) {
                $table->dropColumn('family_status');
            }
        });
    }
};

