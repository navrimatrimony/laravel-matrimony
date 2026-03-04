<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day 31 Part 2 Addendum: One spouse per sibling (optional). Same location strategy as rest of app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_sibling_spouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_sibling_id')
                ->constrained('profile_siblings')
                ->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('occupation_title')->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('address_line')->nullable();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->unsignedBigInteger('taluka_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('profile_sibling_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_sibling_spouses');
    }
};
