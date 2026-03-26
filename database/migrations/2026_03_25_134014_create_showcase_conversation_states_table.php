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
        Schema::create('showcase_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('showcase_profile_id');

            $table->string('automation_status', 32)->default('active'); // active | paused | admin_takeover | silenced

            $table->timestamp('pending_read_at')->nullable();
            $table->timestamp('pending_typing_at')->nullable();
            $table->timestamp('pending_reply_at')->nullable();
            $table->timestamp('pending_offline_at')->nullable();

            $table->timestamp('last_online_at')->nullable();
            $table->timestamp('last_offline_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('last_auto_reply_at')->nullable();
            $table->timestamp('last_admin_reply_at')->nullable();

            $table->unsignedBigInteger('last_incoming_message_id')->nullable();
            $table->unsignedBigInteger('last_outgoing_message_id')->nullable();

            $table->timestamp('admin_takeover_until')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'showcase_profile_id'], 'scs_unique_conversation_showcase');
            $table->index(['pending_read_at'], 'scs_pending_read_at_idx');
            $table->index(['pending_reply_at'], 'scs_pending_reply_at_idx');
            $table->index(['automation_status'], 'scs_automation_status_idx');

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('showcase_profile_id')->references('id')->on('matrimony_profiles')->cascadeOnDelete();
            $table->foreign('last_incoming_message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('last_outgoing_message_id')->references('id')->on('messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('showcase_conversation_states');
    }
};
