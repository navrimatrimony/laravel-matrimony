<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\MessageParticipantState;
use App\Notifications\NewChatMessageNotification;
use App\Services\UserEntitlementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatMessageService
{
    public function __construct(
        protected ChatPolicyService $policy,
    ) {}

    public function getMessagesPaginated(Conversation $conversation, int $perPage = 30): LengthAwarePaginator
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('sent_at', 'desc')
            ->paginate($perPage);
    }

    public function sendTextMessage(MatrimonyProfile $sender, MatrimonyProfile $receiver, Conversation $conversation, string $bodyText): Message
    {
        $bodyText = trim($bodyText);
        if ($bodyText === '') {
            throw ValidationException::withMessages(['body_text' => 'Message cannot be empty.']);
        }
        if (mb_strlen($bodyText) > 2000) {
            throw ValidationException::withMessages(['body_text' => 'Message too long (max 2000 characters).']);
        }

        return $this->sendMessage($sender, $receiver, $conversation, [
            'message_type' => Message::TYPE_TEXT,
            'body_text' => $bodyText,
            'image_path' => null,
        ]);
    }

    public function sendImageMessage(MatrimonyProfile $sender, MatrimonyProfile $receiver, Conversation $conversation, UploadedFile $image, ?string $caption = null): Message
    {
        $cfg = \App\Services\CommunicationPolicyService::getConfig();
        if (! ($cfg['allow_image_messages'] ?? true)) {
            throw ValidationException::withMessages(['image' => 'Image messages are disabled by admin policy.']);
        }

        $audience = (string) ($cfg['image_messages_audience'] ?? 'paid_only');
        if ($audience === 'paid_only') {
            $user = $sender->user;
            if (! $user) {
                throw ValidationException::withMessages(['image' => 'Image messages are available for paid users only.']);
            }
            if (! $user->isAnyAdmin() && ! UserEntitlementService::userHasEntitlement($user, UserEntitlementService::ENTITLEMENT_CHAT_IMAGE_MESSAGES)) {
                throw ValidationException::withMessages(['image' => 'Image messages are locked for free users.']);
            }
        }

        $caption = $caption !== null ? trim($caption) : null;
        if ($caption !== null && mb_strlen($caption) > 500) {
            throw ValidationException::withMessages(['caption' => 'Caption too long (max 500 characters).']);
        }

        $path = $image->store('private/chat-images', ['disk' => 'local']);

        return $this->sendMessage($sender, $receiver, $conversation, [
            'message_type' => Message::TYPE_IMAGE,
            'body_text' => $caption ?: null,
            'image_path' => $path,
        ]);
    }

    public function markConversationRead(MatrimonyProfile $reader, Conversation $conversation): void
    {
        DB::transaction(function () use ($reader, $conversation) {
            $latest = Message::query()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('sent_at')
                ->first();

            $state = MessageParticipantState::firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'profile_id' => $reader->id,
                ],
                [
                    'is_archived' => false,
                    'is_blocked' => false,
                ]
            );

            if ($latest) {
                $state->update([
                    'last_read_message_id' => $latest->id,
                    'last_read_at' => now(),
                ]);

                Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('receiver_profile_id', $reader->id)
                    ->whereNull('read_at')
                    ->update([
                        'read_at' => now(),
                        'delivery_status' => Message::DELIVERY_READ,
                    ]);
            }
        });
    }

    protected function sendMessage(MatrimonyProfile $sender, MatrimonyProfile $receiver, Conversation $conversation, array $payload): Message
    {
        $decision = $this->policy->canSendMessage($sender, $receiver, $conversation);
        if (! $decision->allowed) {
            throw ValidationException::withMessages(['policy' => $decision->humanMessage !== '' ? $decision->humanMessage : 'Cannot send message.']);
        }

        return DB::transaction(function () use ($sender, $receiver, $conversation, $payload) {
            $sentAt = now();

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_profile_id' => $sender->id,
                'receiver_profile_id' => $receiver->id,
                'message_type' => $payload['message_type'],
                'body_text' => $payload['body_text'] ?? null,
                'image_path' => $payload['image_path'] ?? null,
                'sent_at' => $sentAt,
                'read_at' => null,
                'delivery_status' => Message::DELIVERY_SENT,
            ]);

            // Ensure participant state rows exist.
            MessageParticipantState::firstOrCreate(
                ['conversation_id' => $conversation->id, 'profile_id' => $sender->id],
                ['is_archived' => false, 'is_blocked' => false]
            );
            MessageParticipantState::firstOrCreate(
                ['conversation_id' => $conversation->id, 'profile_id' => $receiver->id],
                ['is_archived' => false, 'is_blocked' => false]
            );

            $conversation->update([
                'last_message_id' => $message->id,
                'last_message_at' => $sentAt,
            ]);

            // Reply gate: if receiver replied (i.e. sender is receiver in other direction), clear lock.
            $this->policy->clearReplyGateOnReply($sender, $receiver, $conversation);
            $this->policy->registerReplyGateLockIfNeeded($sender, $receiver, $conversation);

            // Notify receiver (database channel) after successful commit.
            if ($receiver->id !== $sender->id && $receiver->user) {
                $receiverUser = $receiver->user;
                $receiverUser->loadMissing('notifications');

                DB::afterCommit(function () use ($receiverUser, $sender, $conversation, $message) {
                    $receiverUser->notify(new NewChatMessageNotification(
                        senderProfile: $sender,
                        conversationId: (int) $conversation->id,
                        messageType: (string) ($message->message_type ?? 'text'),
                        messagePreview: $message->body_text,
                        messageId: (int) $message->id,
                    ));
                });
            }

            return $message;
        });
    }

    public function streamChatImage(MatrimonyProfile $viewer, Message $message)
    {
        $message->loadMissing('conversation');

        if (! $message->conversation) {
            abort(404);
        }

        $conversation = $message->conversation;
        $isParticipant = in_array((int) $viewer->id, [(int) $conversation->profile_one_id, (int) $conversation->profile_two_id], true);
        if (! $isParticipant) {
            abort(403);
        }

        if ($message->message_type !== Message::TYPE_IMAGE || ! $message->image_path) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($message->image_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($message->image_path);
    }
}

