<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Homepage section images managed from admin (Assisted Service, Elite, Retail, App, Hero, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_section_images', function (Blueprint $table) {
            $table->id();
            $table->string('section_key', 64)->unique();
            $table->string('image_path', 512)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_section_images');
    }
};
