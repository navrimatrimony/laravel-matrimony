<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional display tuning per city row — FK targets {@code addresses}.id (same as legacy cities.id space).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('city_display_meta')) {
            return;
        }

        Schema::create('city_display_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->unique()->constrained('addresses')->cascadeOnDelete();
            $table->boolean('is_district_hq')->default(false);
            $table->unsignedTinyInteger('display_priority')->default(0);
            $table->boolean('hide_state')->default(false);
            $table->boolean('hide_country')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_display_meta');
    }
};
