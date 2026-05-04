<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner preference: acceptable partner occupations ({@see OccupationMaster} / {@code occupation_master}).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_preferred_occupation_master')) {
            return;
        }

        Schema::create('profile_preferred_occupation_master', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->foreignId('occupation_master_id')->constrained('occupation_master')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['profile_id', 'occupation_master_id'], 'pp_occ_prof_occ_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_preferred_occupation_master');
    }
};
