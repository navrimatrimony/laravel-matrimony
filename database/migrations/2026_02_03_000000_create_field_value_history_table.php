<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-3 Day 8: Field value history for historical data protection.
 * Records old/new value on field updates only (no record on create).
 * No hard delete; append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_value_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->string('field_key', 64);
            $table->enum('field_type', ['CORE', 'EXTENDED']);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('changed_by', 32); // USER, ADMIN, MATCHMAKER, SYSTEM
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['profile_id', 'field_key', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_value_history');
    }
};
