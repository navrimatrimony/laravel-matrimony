<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 additive: Master tables for mother_tongue, diet, smoking_status, drinking_status,
 * mangal_status (horoscope), marriage_type_preference (preferences).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_mother_tongues')) {
            Schema::create('master_mother_tongues', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->string('label', 128);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('master_diets')) {
            Schema::create('master_diets', function (Blueprint $table) {
                $table->id();
                $table->string('key', 32)->unique();
                $table->string('label', 64);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('master_smoking_statuses')) {
            Schema::create('master_smoking_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('key', 32)->unique();
                $table->string('label', 64);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('master_drinking_statuses')) {
            Schema::create('master_drinking_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('key', 32)->unique();
                $table->string('label', 64);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('master_mangal_statuses')) {
            Schema::create('master_mangal_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('key', 32)->unique();
                $table->string('label', 64);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('master_marriage_type_preferences')) {
            Schema::create('master_marriage_type_preferences', function (Blueprint $table) {
                $table->id();
                $table->string('key', 32)->unique();
                $table->string('label', 64);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('master_marriage_type_preferences');
        Schema::dropIfExists('master_mangal_statuses');
        Schema::dropIfExists('master_drinking_statuses');
        Schema::dropIfExists('master_smoking_statuses');
        Schema::dropIfExists('master_diets');
        Schema::dropIfExists('master_mother_tongues');
    }
};
