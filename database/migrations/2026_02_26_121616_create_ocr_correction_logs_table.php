<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('ocr_correction_logs', function (Blueprint $table) {
        $table->id();

        $table->unsignedBigInteger('biodata_intake_id');
        $table->string('field_name');
        $table->text('old_value')->nullable();
        $table->text('new_value')->nullable();
        $table->unsignedBigInteger('corrected_by');
		
		$table->foreign('corrected_by')
      ->references('id')
      ->on('users')
      ->restrictOnDelete();

        $table->timestamp('created_at')->useCurrent();

        // Foreign key (NO cascade delete)
        $table->foreign('biodata_intake_id')
              ->references('id')
              ->on('biodata_intakes')
              ->restrictOnDelete();

        // Indexes
        $table->index('biodata_intake_id');
$table->index('field_name');
$table->index('corrected_by');

// Composite for fast diff queries
$table->index(['biodata_intake_id', 'field_name']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::dropIfExists('ocr_correction_logs');
}
};
