<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_profile_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('chat_conversation_id')->nullable()->after('message');
            $table->unsignedBigInteger('chat_message_id')->nullable()->after('chat_conversation_id');
            $table->unsignedBigInteger('replied_by_user_id')->nullable()->after('chat_message_id');
            $table->timestamp('replied_at')->nullable()->after('replied_by_user_id');

            $table->index('chat_conversation_id', 'suchak_requests_chat_conversation_idx');
            $table->index('chat_message_id', 'suchak_requests_chat_message_idx');
            $table->index('replied_at', 'suchak_requests_replied_at_idx');

            $table->foreign('chat_conversation_id', 'suchak_requests_chat_conversation_fk')
                ->references('id')
                ->on('conversations')
                ->nullOnDelete();
            $table->foreign('chat_message_id', 'suchak_requests_chat_message_fk')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
            $table->foreign('replied_by_user_id', 'suchak_requests_replied_by_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suchak_profile_requests', function (Blueprint $table): void {
            $table->dropForeign('suchak_requests_chat_conversation_fk');
            $table->dropForeign('suchak_requests_chat_message_fk');
            $table->dropForeign('suchak_requests_replied_by_user_fk');

            $table->dropIndex('suchak_requests_chat_conversation_idx');
            $table->dropIndex('suchak_requests_chat_message_idx');
            $table->dropIndex('suchak_requests_replied_at_idx');

            $table->dropColumn([
                'chat_conversation_id',
                'chat_message_id',
                'replied_by_user_id',
                'replied_at',
            ]);
        });
    }
};
