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
        Schema::create('showcase_presence_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('showcase_profile_id');
            $table->unsignedBigInteger('conversation_id')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('trigger_type', 32)->default('scheduled'); // scheduled | incoming_message | reply_flow | admin_takeover
            $table->timestamps();

            $table->index(['showcase_profile_id', 'ended_at'], 'sps_active_idx');
            $table->index(['scheduled_end_at'], 'sps_scheduled_end_at_idx');

            $table->foreign('showcase_profile_id')->references('id')->on('matrimony_profiles')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('showcase_presence_sessions');
    }
};
