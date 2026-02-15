<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_extended_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->unique()
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->longText('narrative_about_me')->nullable();
            $table->longText('narrative_expectations')->nullable();
            $table->longText('additional_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_extended_attributes');
    }
};
