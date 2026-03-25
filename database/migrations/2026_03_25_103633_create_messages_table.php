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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_profile_id')->constrained('matrimony_profiles');
            $table->foreignId('receiver_profile_id')->constrained('matrimony_profiles');

            $table->enum('message_type', ['text', 'image'])->default('text');
            $table->text('body_text')->nullable();
            $table->string('image_path')->nullable();

            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();
            $table->enum('delivery_status', ['sent', 'read'])->default('sent');
            $table->timestamps();

            $table->index(['conversation_id', 'sent_at'], 'messages_conversation_sent_at_idx');
            $table->index(['receiver_profile_id', 'read_at'], 'messages_receiver_read_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
