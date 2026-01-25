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
        Schema::dropIfExists('blocks');
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->foreignId('blocked_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['blocker_profile_id', 'blocked_profile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
