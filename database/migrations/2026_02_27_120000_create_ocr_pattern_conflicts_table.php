<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store proposed corrections that conflict with an existing pattern (X->Z already exists, user proposed X->Y).
 * No overwrite; review required. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_pattern_conflicts')) {
            return;
        }

        Schema::create('ocr_pattern_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('field_key', 64)->index();
            $table->string('wrong_pattern', 255);
            $table->string('existing_corrected_value', 512);
            $table->string('proposed_corrected_value', 512);
            $table->unsignedInteger('observation_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_pattern_conflicts');
    }
};
