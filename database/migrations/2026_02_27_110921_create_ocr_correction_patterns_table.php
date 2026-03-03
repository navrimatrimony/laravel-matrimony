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
    Schema::create('ocr_correction_patterns', function (Blueprint $table) {
        $table->id();
        $table->string('field_key');
        $table->string('wrong_pattern');
        $table->string('corrected_value');
        $table->decimal('pattern_confidence', 5, 2)->default(0);
        $table->integer('usage_count')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::dropIfExists('ocr_correction_patterns');
}
};
