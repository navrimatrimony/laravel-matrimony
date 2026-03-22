<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_preferred_countries')) {
            Schema::create('profile_preferred_countries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
                $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('profile_preferred_states')) {
            Schema::create('profile_preferred_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
                $table->foreignId('state_id')->constrained('states')->cascadeOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('profile_preferred_talukas')) {
            Schema::create('profile_preferred_talukas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
                $table->foreignId('taluka_id')->constrained('talukas')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_preferred_talukas');
        Schema::dropIfExists('profile_preferred_states');
        Schema::dropIfExists('profile_preferred_countries');
    }
};
