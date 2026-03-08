<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary master: Income ranges for dropdown.
 * Replace with exact Shaadi-style data later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('slug', 128)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_ranges');
    }
};
