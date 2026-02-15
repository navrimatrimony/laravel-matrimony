<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_property_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->unique()
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->boolean('owns_house')->default(false);
            $table->boolean('owns_flat')->default(false);
            $table->boolean('owns_agriculture')->default(false);
            $table->decimal('total_land_acres', 10, 2)->nullable();
            $table->decimal('annual_agri_income', 12, 2)->nullable();
            $table->longText('summary_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_property_summary');
    }
};
