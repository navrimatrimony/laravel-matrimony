<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_education', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('degree');
            $table->string('specialization')->nullable();
            $table->string('university')->nullable();
            $table->unsignedInteger('year_completed');
            $table->timestamps();

            $table->index('profile_id');
            $table->index('year_completed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_education');
    }
};
