<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hidden_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->foreignId('hidden_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['owner_profile_id', 'hidden_profile_id']);
            $table->index('owner_profile_id');
            $table->index('hidden_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_profiles');
    }
};
