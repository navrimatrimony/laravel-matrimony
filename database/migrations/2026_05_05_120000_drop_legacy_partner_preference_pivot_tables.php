<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner preferences now use {@see profile_preferred_education_degrees} and
 * {@see profile_preferred_occupation_master} only. These legacy pivots are unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('profile_preferred_professions');
        Schema::dropIfExists('profile_preferred_working_with_types');
        Schema::dropIfExists('profile_preferred_master_education');
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_preferred_master_education')) {
            Schema::create('profile_preferred_master_education', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
                $table->foreignId('master_education_id')->constrained('master_education')->cascadeOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('profile_preferred_working_with_types')) {
            Schema::create('profile_preferred_working_with_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('profile_id');
                $table->unsignedBigInteger('working_with_type_id');
                $table->timestamps();
                $table->foreign('profile_id', 'fk_ppwwt_profile')->references('id')->on('matrimony_profiles')->cascadeOnDelete();
                $table->foreign('working_with_type_id', 'fk_ppwwt_wwtype')->references('id')->on('working_with_types')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('profile_preferred_professions')) {
            Schema::create('profile_preferred_professions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
                $table->foreignId('profession_id')->constrained('professions')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }
};
