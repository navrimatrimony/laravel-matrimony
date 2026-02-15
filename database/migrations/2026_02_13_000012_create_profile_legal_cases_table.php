<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_legal_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('case_type');
            $table->string('court_name')->nullable();
            $table->string('case_number')->nullable();
            $table->string('case_stage')->nullable();
            $table->date('next_hearing_date')->nullable();
            $table->boolean('active_status')->default(true);
            $table->longText('notes')->nullable();
            $table->timestamps();

            $table->index('profile_id');
            $table->index('active_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_legal_cases');
    }
};
