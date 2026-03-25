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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_one_id')->constrained('matrimony_profiles');
            $table->foreignId('profile_two_id')->constrained('matrimony_profiles');
            $table->foreignId('created_by_profile_id')->constrained('matrimony_profiles');
            $table->enum('status', ['active', 'blocked', 'archived'])->default('active');
            // messages table is created after conversations in this migration order,
            // so keep as plain nullable id (FK can be added in a later additive migration if needed).
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_one_id', 'profile_two_id'], 'conversations_unique_pair');
            $table->index(['profile_one_id', 'profile_two_id'], 'conversations_pair_idx');
            $table->index(['last_message_at'], 'conversations_last_message_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
