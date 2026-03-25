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
        Schema::create('message_participant_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('matrimony_profiles');
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages');
            $table->timestamp('last_read_at')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();

            $table->unique(['conversation_id', 'profile_id'], 'mps_unique_conversation_profile');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_participant_states');
    }
};
