<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_matches')) {
            return;
        }

        Schema::create('profile_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->foreignId('matched_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->json('json_reasons')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'matched_profile_id']);
            $table->index(['profile_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_matches');
    }
};
