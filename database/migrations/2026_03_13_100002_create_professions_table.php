<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary master: Professions (Working As).
 * working_with_type_id optional for future dependency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('working_with_type_id')->nullable()->constrained('working_with_types')->nullOnDelete();
            $table->string('name', 128);
            $table->string('slug', 128)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professions');
    }
};
