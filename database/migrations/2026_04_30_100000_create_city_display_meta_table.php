<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5 completion: deterministic overrides (HQ mismatch, hide state/country in UI).
 * Nullable booleans = inherit formatter defaults; explicit true/false = override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_display_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->unique()->constrained('cities')->cascadeOnDelete();
            $table->boolean('is_district_hq')->nullable();
            $table->smallInteger('display_priority')->default(0);
            $table->boolean('hide_state')->nullable();
            $table->boolean('hide_country')->nullable();
            $table->timestamps();

            $table->index(['display_priority', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_display_meta');
    }
};
