<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single geographic hierarchy + pincode SSOT (country → … → village/suburb).
 * Legacy separate tables (countries, states, cities, …) are removed; all IDs live here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addresses')) {
            return;
        }

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_mr')->nullable();
            $table->string('name_en')->nullable();
            $table->string('iso_alpha2', 2)->nullable()->index();
            $table->string('slug')->unique();
            $table->enum('type', [
                'country',
                'state',
                'district',
                'taluka',
                'city',
                'suburb',
                'village',
            ]);
            $table->string('tag', 32)->nullable()->comment('metro/city/town/village/suburban — UI category');
            $table->foreignId('parent_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('state_code', 32)->nullable();
            $table->string('district_code', 32)->nullable();
            $table->string('pincode', 16)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('lgd_code', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('population')->nullable()->comment('city-level census/heuristic; optional');
            $table->timestamps();

            $table->index('name');
            $table->index('parent_id');
            $table->index('type');
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
