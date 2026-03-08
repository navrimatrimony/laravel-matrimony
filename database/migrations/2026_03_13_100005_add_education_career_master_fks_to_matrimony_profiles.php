<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: Education & Career engine — new master FKs and income privacy.
 * Existing columns (highest_education, company_name, annual_income, etc.) unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('matrimony_profiles', 'working_with_type_id')) {
                $table->foreignId('working_with_type_id')->nullable()->after('occupation_title')->constrained('working_with_types')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'profession_id')) {
                $table->foreignId('profession_id')->nullable()->after('working_with_type_id')->constrained('professions')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'income_range_id')) {
                $table->foreignId('income_range_id')->nullable()->after('annual_income')->constrained('income_ranges')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'college_id')) {
                $table->foreignId('college_id')->nullable()->after('specialization')->constrained('colleges')->nullOnDelete();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'income_private')) {
                $table->boolean('income_private')->default(false)->after('income_range_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'working_with_type_id')) {
                $table->dropForeign(['working_with_type_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'profession_id')) {
                $table->dropForeign(['profession_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'income_range_id')) {
                $table->dropForeign(['income_range_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'college_id')) {
                $table->dropForeign(['college_id']);
            }
            if (Schema::hasColumn('matrimony_profiles', 'income_private')) {
                $table->dropColumn('income_private');
            }
        });
    }
};
