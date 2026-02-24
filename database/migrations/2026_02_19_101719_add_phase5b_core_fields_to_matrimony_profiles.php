<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {

            // PERSONAL
            $table->string('religion')->nullable()->index();
            $table->string('sub_caste')->nullable()->index();
            $table->integer('weight_kg')->nullable();
            $table->string('complexion')->nullable();
            $table->string('physical_build')->nullable();
            $table->string('blood_group')->nullable();

            // EDUCATION & CAREER SNAPSHOT
            $table->string('highest_education')->nullable();
            $table->string('specialization')->nullable();
            $table->string('occupation_title')->nullable();
            $table->string('company_name')->nullable();
            $table->decimal('annual_income', 12, 2)->nullable()->index();
            $table->string('income_currency', 10)->default('INR');
            $table->decimal('family_income', 12, 2)->nullable()->index();

            // FAMILY CORE
            $table->string('father_name')->nullable();
            $table->string('father_occupation')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('mother_occupation')->nullable();
            $table->integer('brothers_count')->nullable();
            $table->integer('sisters_count')->nullable();
            $table->string('family_type')->nullable();

            // WORK LOCATION
            $table->unsignedBigInteger('work_city_id')->nullable()->index();
            $table->unsignedBigInteger('work_state_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {

            $table->dropColumn([
                'religion',
                'sub_caste',
                'weight_kg',
                'complexion',
                'physical_build',
                'blood_group',
                'highest_education',
                'specialization',
                'occupation_title',
                'company_name',
                'annual_income',
                'income_currency',
                'family_income',
                'father_name',
                'father_occupation',
                'mother_name',
                'mother_occupation',
                'brothers_count',
                'sisters_count',
                'family_type',
                'work_city_id',
                'work_state_id',
            ]);
        });
    }
};
