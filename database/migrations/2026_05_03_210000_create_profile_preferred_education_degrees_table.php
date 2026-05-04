<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner preference: acceptable partner qualification degrees (SSOT: education_degrees).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_preferred_education_degrees')) {
            return;
        }

        Schema::create('profile_preferred_education_degrees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->foreignId('education_degree_id')->constrained('education_degrees')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['profile_id', 'education_degree_id'], 'pp_edu_deg_profile_degree_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_preferred_education_degrees');
    }
};
