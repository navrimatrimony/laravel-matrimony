<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_career', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('designation');
            $table->string('company');
            $table->string('location')->nullable();
            $table->unsignedInteger('start_year');
            $table->unsignedInteger('end_year')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index('profile_id');
            $table->index('is_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_career');
    }
};
