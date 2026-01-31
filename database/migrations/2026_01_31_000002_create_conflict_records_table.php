<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase-3 Day-4: Conflict Record System Foundation.
     */
    public function up(): void
    {
        Schema::create('conflict_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->string('field_name');
            $table->enum('field_type', ['CORE', 'EXTENDED']);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->enum('source', ['OCR', 'USER', 'ADMIN', 'MATCHMAKER', 'SYSTEM']);
            $table->timestamp('detected_at');
            $table->enum('resolution_status', ['PENDING', 'APPROVED', 'REJECTED', 'OVERRIDDEN'])->default('PENDING');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('matrimony_profiles');
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conflict_records');
    }
};
