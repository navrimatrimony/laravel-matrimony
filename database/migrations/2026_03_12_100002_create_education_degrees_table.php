<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_degrees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                ->constrained('education_categories')
                ->cascadeOnDelete();
            $table->string('code', 128);
            $table->string('title', 128);
            $table->string('full_form')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_degrees');
    }
};
