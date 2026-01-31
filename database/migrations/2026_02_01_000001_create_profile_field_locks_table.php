<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-3 Day-6: Per-profile field lock metadata.
 * Lock applies per (profile_id, field_key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_field_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->string('field_key', 64);
            $table->enum('field_type', ['CORE', 'EXTENDED']);
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('matrimony_profiles');
            $table->foreign('locked_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['profile_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_field_locks');
    }
};
