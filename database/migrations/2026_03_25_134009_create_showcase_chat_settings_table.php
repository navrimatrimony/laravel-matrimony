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
        Schema::create('showcase_chat_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('matrimony_profile_id')->unique();

            $table->boolean('enabled')->default(false);
            $table->boolean('ai_assisted_replies_enabled')->default(true);
            $table->boolean('admin_takeover_enabled')->default(true);

            // Business hours
            $table->boolean('business_hours_enabled')->default(true);
            $table->json('business_days_json')->nullable(); // minimal JSON: e.g. [1,2,3,4,5,6,7]
            $table->time('business_hours_start')->nullable();
            $table->time('business_hours_end')->nullable();

            // Off-hours behavior toggles
            $table->boolean('off_hours_online_allowed')->default(false);
            $table->boolean('off_hours_read_allowed')->default(false);
            $table->boolean('off_hours_reply_allowed')->default(false);

            // Presence timing
            $table->unsignedInteger('online_session_min_minutes')->default(3);
            $table->unsignedInteger('online_session_max_minutes')->default(8);
            $table->unsignedInteger('offline_gap_min_minutes')->default(10);
            $table->unsignedInteger('offline_gap_max_minutes')->default(45);
            $table->unsignedInteger('online_before_read_min_seconds')->default(8);
            $table->unsignedInteger('online_before_read_max_seconds')->default(35);
            $table->unsignedInteger('online_linger_after_reply_min_seconds')->default(20);
            $table->unsignedInteger('online_linger_after_reply_max_seconds')->default(90);

            // Read timing
            $table->unsignedInteger('read_delay_min_minutes')->default(2);
            $table->unsignedInteger('read_delay_max_minutes')->default(12);
            $table->boolean('read_only_when_online')->default(true);
            $table->unsignedInteger('force_read_by_max_hours')->nullable();
            $table->boolean('batch_read_enabled')->default(true);
            $table->unsignedInteger('batch_read_window_min_minutes')->nullable();
            $table->unsignedInteger('batch_read_window_max_minutes')->nullable();

            // Reply timing
            $table->unsignedInteger('reply_delay_min_minutes')->default(3);
            $table->unsignedInteger('reply_delay_max_minutes')->default(25);
            $table->unsignedInteger('reply_after_read_min_minutes')->default(1);
            $table->unsignedInteger('reply_after_read_max_minutes')->default(12);
            $table->unsignedInteger('max_replies_per_day')->nullable();
            $table->unsignedInteger('max_replies_per_conversation_per_day')->nullable();
            $table->unsignedInteger('cooldown_after_last_outgoing_min_minutes')->nullable();
            $table->unsignedInteger('cooldown_after_last_outgoing_max_minutes')->nullable();

            // Typing
            $table->boolean('typing_enabled')->default(true);
            $table->unsignedInteger('typing_duration_min_seconds')->default(6);
            $table->unsignedInteger('typing_duration_max_seconds')->default(18);

            // Realism percentages
            $table->unsignedTinyInteger('reply_probability_percent')->default(65);
            $table->unsignedTinyInteger('initiate_probability_percent')->default(15);

            // Safety
            $table->unsignedInteger('no_reply_after_unanswered_count')->nullable();
            $table->boolean('pause_on_sensitive_keywords')->default(true);
            $table->boolean('is_paused')->default(false);
            $table->timestamps();

            $table->foreign('matrimony_profile_id')
                ->references('id')
                ->on('matrimony_profiles')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('showcase_chat_settings');
    }
};
