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
        Schema::table('showcase_chat_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('showcase_chat_settings', 'personality_preset')) {
                $table->string('personality_preset', 32)->default('balanced')->after('is_paused');
            }
            if (! Schema::hasColumn('showcase_chat_settings', 'reply_length_min_words')) {
                $table->unsignedInteger('reply_length_min_words')->nullable()->default(4)->after('personality_preset');
            }
            if (! Schema::hasColumn('showcase_chat_settings', 'reply_length_max_words')) {
                $table->unsignedInteger('reply_length_max_words')->nullable()->default(18)->after('reply_length_min_words');
            }
            if (! Schema::hasColumn('showcase_chat_settings', 'style_variation_enabled')) {
                $table->boolean('style_variation_enabled')->default(true)->after('reply_length_max_words');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('showcase_chat_settings', function (Blueprint $table) {
            if (Schema::hasColumn('showcase_chat_settings', 'style_variation_enabled')) {
                $table->dropColumn('style_variation_enabled');
            }
            if (Schema::hasColumn('showcase_chat_settings', 'reply_length_max_words')) {
                $table->dropColumn('reply_length_max_words');
            }
            if (Schema::hasColumn('showcase_chat_settings', 'reply_length_min_words')) {
                $table->dropColumn('reply_length_min_words');
            }
            if (Schema::hasColumn('showcase_chat_settings', 'personality_preset')) {
                $table->dropColumn('personality_preset');
            }
        });
    }
};
