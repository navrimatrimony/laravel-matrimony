<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('profile_preferred_castes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
        $table->foreignId('caste_id')->constrained('castes')->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('profile_preferred_districts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
        $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('profile_preferred_religions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
        $table->foreignId('religion_id')->constrained('religions')->cascadeOnDelete();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('profile_preferred_religions');
    Schema::dropIfExists('profile_preferred_districts');
    Schema::dropIfExists('profile_preferred_castes');
}
};
