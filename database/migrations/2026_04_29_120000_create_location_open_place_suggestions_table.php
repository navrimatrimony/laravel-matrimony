<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4 (free-text / partial hierarchy): NEW table — does not alter {@see location_suggestions} (PHASE-5).
 *
 * Holds user-entered places (e.g. “Wakad”) before full country→taluka resolution; optional FKs when known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_open_place_suggestions', function (Blueprint $table) {
            $table->id();
            $table->text('raw_input');
            $table->string('normalized_input');
            $table->foreignId('country_id')->nullable()->constrained('addresses')->restrictOnDelete();
            $table->foreignId('state_id')->nullable()->constrained('addresses')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('addresses')->restrictOnDelete();
            $table->foreignId('taluka_id')->nullable()->constrained('addresses')->restrictOnDelete();
            $table->foreignId('resolved_city_id')->nullable()->constrained('addresses')->restrictOnDelete();
            $table->string('match_type', 24)->default('none');
            $table->decimal('confidence_score', 8, 6)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('usage_count')->default(0);
            $table->foreignId('suggested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('admin_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('admin_reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'normalized_input']);
            $table->index('normalized_input');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_open_place_suggestions');
    }
};
