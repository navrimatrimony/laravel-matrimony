<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Services\ChatListService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ChatApiPresenter
 *
 * The single JSON shape for chat conversations/messages/threads across every
 * API surface. Extracted verbatim out of MobileChatApiController so the Suchak
 * chat endpoints can reuse the member contract instead of growing a second,
 * drifting one. There is exactly ONE chat engine and ONE chat payload shape.
 *
 * Deliberately contains no phone/contact fields: contact numbers are owned by
 * the consent + contact-request pipeline, never by chat.
 */
class ChatApiPresenter
{
    public const AUTHOR_MEMBER = 'member';

    public function __construct(
        protected ChatConversationService $conversations,
        protected ChatMessageModerationService $moderation,
        protected ChatListService $chatList,
    ) {}

    /**
     * Where the "unread from here" divider goes for this viewer in this thread.
     *
     * Delegates to {@see ChatListService::getConversationUnreadSummary()} so the
     * member thread, the Suchak thread and the inbox badge all count the same
     * rows. Callers must take this snapshot BEFORE marking the thread read —
     * the response that carries the divider is the very response that clears it.
     *
     * @return array{unread_count: int, first_unread_message_id: int|null}
     */
    public function threadUnread(Conversation $conversation, MatrimonyProfile $viewer): array
    {
        return $this->chatList->getConversationUnreadSummary((int) $viewer->id, (int) $conversation->id);
    }

    /**
     * Thread window. `since_id > 0` is the polling path (ascending, new only);
     * otherwise the newest 50 sorted back into chronological order.
     *
     * @return Collection<int, Message>
     */
    public function threadMessages(Conversation $conversation, int $sinceId): Collection
    {
        if ($sinceId > 0) {
            return Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('id', '>', $sinceId)
                ->orderBy('id', 'asc')
                ->limit(50)
                ->get();
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->sortBy(fn (Message $message): string => sprintf(
                '%020d-%020d',
                $message->sent_at?->timestamp ?? 0,
                (int) $message->id
            ))
            ->values();
    }

    public function conversationPayload(
        Conversation $conversation,
        MatrimonyProfile $viewer,
        bool $readLockedForIncoming,
        ?MatrimonyProfile $other = null
    ): array {
        $conversation->loadMissing(['lastMessage.senderProfile', 'lastMessage.receiverProfile']);
        if (! $other instanceof MatrimonyProfile) {
            $other = $conversation->relationLoaded('other_profile')
                && $conversation->getRelation('other_profile') instanceof MatrimonyProfile
                ? $conversation->getRelation('other_profile')
                : $this->conversations->getOtherParticipant($conversation, $viewer);
        }

        $last = $conversation->lastMessage;

        return [
            'id' => (int) $conversation->id,
            'status' => (string) ($conversation->status ?? ''),
            'profile_one_id' => (int) $conversation->profile_one_id,
            'profile_two_id' => (int) $conversation->profile_two_id,
            'created_by_profile_id' => (int) $conversation->created_by_profile_id,
            'last_message_at' => $this->dateValue($conversation->last_message_at),
            'unread_count' => (int) ($conversation->getAttribute('unread_count') ?? 0),
            'other_profile' => $this->profilePayload($other),
            'last_message' => $last instanceof Message
                ? $this->messagePayload($last, $viewer, $readLockedForIncoming)
                : null,
            'preview' => $last instanceof Message
                ? $this->previewLineForMessage($last, $viewer, ! $readLockedForIncoming)
                : null,
        ];
    }

    public function messagePayload(Message $message, MatrimonyProfile $viewer, bool $readLockedForIncoming): array
    {
        $isMine = (int) $message->sender_profile_id === (int) $viewer->id;
        $incomingLocked = ! $isMine && $readLockedForIncoming;
        $display = ['text' => null, 'show_filtered_badge' => false];
        if (! $incomingLocked) {
            $display = $this->moderation->bodyTextForViewer($message, (int) $viewer->id, false);
        }

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_profile_id' => (int) $message->sender_profile_id,
            'receiver_profile_id' => (int) $message->receiver_profile_id,
            'is_mine' => $isMine,
            'message_type' => (string) ($message->message_type ?? Message::TYPE_TEXT),
            'body_text' => $incomingLocked ? null : $this->cleanString($display['text'] ?? null),
            'preview_text' => $incomingLocked ? (string) __('chat_ui.read_locked_preview') : $this->cleanString($display['text'] ?? null),
            'read_locked' => $incomingLocked,
            'show_filtered_badge' => (bool) ($display['show_filtered_badge'] ?? false),
            // The neutral, always-present base: in a member↔member thread every
            // message is authored by a member, and the label is simply who sent
            // it (already exposed as `sender.name`, so no new disclosure and
            // nothing to withhold when the body is read-locked).
            //
            // A Suchak relay is STORED under the candidate's profile, so the
            // sender id alone cannot tell `candidate` from `suchak`. Only the
            // Suchak surface knows which side of the request the viewer sits on,
            // so it — and only it — narrows this to `candidate`/`suchak`:
            // {@see \App\Modules\Suchak\Services\SuchakChatThreadService::messagePayload()}.
            'author_role' => self::AUTHOR_MEMBER,
            'author_label' => $this->cleanString($message->senderProfile?->full_name),
            // Always the RECIPIENT's view of the message, so the sender's own
            // bubble can show ✓ vs ✓✓. Written by
            // {@see \App\Services\Chat\ChatMessageService::applyConversationRead()}
            // alongside read_at, in the same transaction.
            'delivery_status' => (string) ($message->delivery_status ?: Message::DELIVERY_SENT),
            'sent_at' => $this->dateValue($message->sent_at),
            'read_at' => $this->dateValue($message->read_at),
            'sender' => $this->profilePayload($message->senderProfile),
            'receiver' => $this->profilePayload($message->receiverProfile),
        ];
    }

    public function previewLineForMessage(Message $message, MatrimonyProfile $viewer, bool $viewerCanReadIncoming): string
    {
        if ((int) $message->sender_profile_id !== (int) $viewer->id && ! $viewerCanReadIncoming) {
            return (string) __('chat_ui.read_locked_preview');
        }

        $display = $this->moderation->bodyTextForViewer($message, (int) $viewer->id, false);
        $text = $this->cleanString($display['text'] ?? null);
        if (($message->message_type ?? Message::TYPE_TEXT) === Message::TYPE_IMAGE) {
            return $text !== null ? 'Image: '.$text : 'Image';
        }

        return $text ?? '';
    }

    public function profilePayload(?MatrimonyProfile $profile): ?array
    {
        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        $profile->loadMissing(['gender', 'religion', 'caste', 'location']);

        return [
            'id' => (int) $profile->id,
            'name' => $this->cleanString($profile->full_name) ?? 'Profile',
            'age' => $this->age($profile),
            'profile_photo_url' => $profile->photo_approved !== false ? $profile->profile_photo_url : null,
            'community' => $this->joinClean([
                $this->cleanString($profile->religion?->name ?? $profile->religion?->label ?? null),
                $this->cleanString($profile->caste?->name ?? $profile->caste?->label ?? null),
            ]),
            'location' => $this->locationLabel($profile),
        ];
    }

    public function policyPayload(PolicyDecision $decision): array
    {
        return [
            'allowed' => $decision->allowed,
            'code' => $decision->code,
            'message' => $this->cleanString($decision->humanMessage),
            'locked_until' => $this->dateValue($decision->lockedUntil),
            'meta' => $decision->meta,
        ];
    }

    public function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $this->cleanString($value);
    }

    private function locationLabel(MatrimonyProfile $profile): ?string
    {
        if (method_exists($profile, 'residenceLocationDisplayLine')) {
            $line = $this->cleanString($profile->residenceLocationDisplayLine());
            if ($line !== null) {
                return $line;
            }
        }

        return $this->cleanString($profile->location?->name ?? $profile->location?->label ?? null);
    }

    private function age(MatrimonyProfile $profile): ?int
    {
        $date = $this->cleanString($profile->date_of_birth);
        if ($date === null) {
            return null;
        }

        try {
            return Carbon::parse($date)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function joinClean(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn (mixed $value): bool => $this->cleanString($value) !== null));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function cleanString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
