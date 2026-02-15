<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->unique()
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('preferred_city')->nullable();
            $table->string('preferred_caste')->nullable();
            $table->unsignedInteger('preferred_age_min')->nullable();
            $table->unsignedInteger('preferred_age_max')->nullable();
            $table->decimal('preferred_income_min', 12, 2)->nullable();
            $table->decimal('preferred_income_max', 12, 2)->nullable();
            $table->string('preferred_education')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_preferences');
    }
};
