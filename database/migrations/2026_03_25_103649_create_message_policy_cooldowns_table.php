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
        Schema::create('message_policy_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_profile_id')->constrained('matrimony_profiles');
            $table->foreignId('receiver_profile_id')->constrained('matrimony_profiles');
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->enum('reason', ['reply_gate_limit', 'admin_action'])->default('reply_gate_limit');
            $table->timestamp('locked_until');
            $table->timestamps();

            $table->unique(['sender_profile_id', 'receiver_profile_id', 'reason'], 'mpc_unique_pair_reason');
            $table->index(['locked_until'], 'mpc_locked_until_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_policy_cooldowns');
    }
};
