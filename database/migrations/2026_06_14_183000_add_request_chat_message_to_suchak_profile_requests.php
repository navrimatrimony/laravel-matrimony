<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_profile_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('request_chat_message_id')->nullable()->after('message');

            $table->index('request_chat_message_id', 'suchak_requests_request_chat_message_idx');
            $table->foreign('request_chat_message_id', 'suchak_requests_request_chat_message_fk')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
        });

        $this->backfillRequestMessagesIntoChat();
    }

    public function down(): void
    {
        Schema::table('suchak_profile_requests', function (Blueprint $table): void {
            $table->dropForeign('suchak_requests_request_chat_message_fk');
            $table->dropIndex('suchak_requests_request_chat_message_idx');
            $table->dropColumn('request_chat_message_id');
        });
    }

    private function backfillRequestMessagesIntoChat(): void
    {
        if (! Schema::hasTable('suchak_profile_requests') || ! Schema::hasTable('messages')) {
            return;
        }

        $requests = DB::table('suchak_profile_requests')
            ->whereNull('request_chat_message_id')
            ->whereNotNull('message')
            ->whereRaw("TRIM(message) <> ''")
            ->orderBy('id')
            ->get([
                'id',
                'requesting_matrimony_profile_id',
                'target_matrimony_profile_id',
                'chat_conversation_id',
                'message',
                'created_at',
                'updated_at',
            ]);

        foreach ($requests as $request) {
            $senderProfileId = (int) $request->requesting_matrimony_profile_id;
            $receiverProfileId = (int) $request->target_matrimony_profile_id;
            if ($senderProfileId <= 0 || $receiverProfileId <= 0 || $senderProfileId === $receiverProfileId) {
                continue;
            }

            [$profileOneId, $profileTwoId] = $senderProfileId < $receiverProfileId
                ? [$senderProfileId, $receiverProfileId]
                : [$receiverProfileId, $senderProfileId];

            $timestamp = $request->created_at ?: now();
            $now = now();

            $conversation = DB::table('conversations')
                ->where('profile_one_id', $profileOneId)
                ->where('profile_two_id', $profileTwoId)
                ->first(['id']);

            $conversationId = $conversation?->id;
            if (! $conversationId) {
                $conversationId = DB::table('conversations')->insertGetId([
                    'profile_one_id' => $profileOneId,
                    'profile_two_id' => $profileTwoId,
                    'created_by_profile_id' => $senderProfileId,
                    'status' => 'active',
                    'last_message_id' => null,
                    'last_message_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }

            $body = trim((string) $request->message);
            $existingMessage = DB::table('messages')
                ->where('conversation_id', $conversationId)
                ->where('sender_profile_id', $senderProfileId)
                ->where('receiver_profile_id', $receiverProfileId)
                ->where('message_type', 'text')
                ->where('body_text', $body)
                ->orderBy('sent_at')
                ->orderBy('id')
                ->first(['id']);

            $messageId = $existingMessage?->id;
            if (! $messageId) {
                $messageId = DB::table('messages')->insertGetId([
                    'conversation_id' => $conversationId,
                    'sender_profile_id' => $senderProfileId,
                    'receiver_profile_id' => $receiverProfileId,
                    'message_type' => 'text',
                    'body_text' => $body,
                    'image_path' => null,
                    'sent_at' => $timestamp,
                    'read_at' => null,
                    'delivery_status' => 'sent',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }

            foreach ([$senderProfileId, $receiverProfileId] as $profileId) {
                $stateExists = DB::table('message_participant_states')
                    ->where('conversation_id', $conversationId)
                    ->where('profile_id', $profileId)
                    ->exists();

                if (! $stateExists) {
                    DB::table('message_participant_states')->insert([
                        'conversation_id' => $conversationId,
                        'profile_id' => $profileId,
                        'last_read_message_id' => null,
                        'last_read_at' => null,
                        'is_archived' => false,
                        'is_blocked' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('suchak_profile_requests')
                ->where('id', $request->id)
                ->update([
                    'chat_conversation_id' => $conversationId,
                    'request_chat_message_id' => $messageId,
                    'updated_at' => $request->updated_at ?: $now,
                ]);

            $latestMessage = DB::table('messages')
                ->where('conversation_id', $conversationId)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first(['id', 'sent_at']);

            if ($latestMessage) {
                DB::table('conversations')
                    ->where('id', $conversationId)
                    ->update([
                        'last_message_id' => $latestMessage->id,
                        'last_message_at' => $latestMessage->sent_at,
                        'updated_at' => $now,
                    ]);
            }
        }
    }
};
