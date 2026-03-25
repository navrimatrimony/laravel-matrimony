<?php

namespace App\Services\Chat;

use App\Models\Block;
use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\MessagePolicyCooldown;
use App\Services\CommunicationPolicyService;
use App\Services\ContactRequestService;
use Illuminate\Support\Facades\DB;

class ChatPolicyService
{
    public function __construct(
        protected ContactRequestService $contactRequestService,
    ) {}

    public function canAccessMessaging(MatrimonyProfile $sender, MatrimonyProfile $receiver): PolicyDecision
    {
        if ($sender->id === $receiver->id) {
            return PolicyDecision::deny('same_profile', 'You cannot message yourself.');
        }

        if (($sender->lifecycle_state ?? '') !== 'active' || ($receiver->lifecycle_state ?? '') !== 'active') {
            return PolicyDecision::deny('inactive_profile', 'Messaging is available only for active profiles.');
        }

        if (($sender->is_suspended ?? false) || ($receiver->is_suspended ?? false)) {
            return PolicyDecision::deny('suspended_profile', 'Messaging is unavailable for suspended profiles.');
        }

        $cfg = CommunicationPolicyService::getConfig();
        if (! ($cfg['allow_messaging'] ?? true)) {
            return PolicyDecision::deny('messaging_disabled', 'Messaging is currently disabled by admin policy.');
        }

        if ($this->isPairBlocked($sender->id, $receiver->id)) {
            return PolicyDecision::deny('blocked_pair', 'You cannot message this profile.');
        }

        $mode = (string) ($cfg['messaging_mode'] ?? 'free_chat_with_reply_gate');
        if ($mode === 'contact_request_required') {
            if (! $sender->user || ! $receiver->user) {
                return PolicyDecision::deny('contact_grant_required', 'Chat is available only after contact access is approved.');
            }

            $grant = $this->contactRequestService->getEffectiveGrant($sender->user, $receiver->user);
            if (! $grant) {
                return PolicyDecision::deny('contact_grant_required', 'Chat is available only after contact access is approved.');
            }
        }

        return PolicyDecision::allow(['messaging_mode' => $mode]);
    }

    public function canStartConversation(MatrimonyProfile $sender, MatrimonyProfile $receiver): PolicyDecision
    {
        $access = $this->canAccessMessaging($sender, $receiver);
        if (! $access->allowed) {
            return $access;
        }

        $cfg = CommunicationPolicyService::getConfig();
        $maxNew = (int) ($cfg['max_new_conversations_per_day'] ?? 10);
        if ($maxNew > 0) {
            $start = today();
            $count = Conversation::query()
                ->where('created_by_profile_id', $sender->id)
                ->where('created_at', '>=', $start)
                ->count();
            if ($count >= $maxNew) {
                return PolicyDecision::deny('new_conversations_daily_limit', 'You have reached the daily limit for new conversations.');
            }
        }

        return PolicyDecision::allow($access->meta);
    }

    public function canSendMessage(MatrimonyProfile $sender, MatrimonyProfile $receiver, Conversation $conversation): PolicyDecision
    {
        $access = $this->canAccessMessaging($sender, $receiver);
        if (! $access->allowed) {
            return $access;
        }

        if (($conversation->status ?? '') !== Conversation::STATUS_ACTIVE) {
            return PolicyDecision::deny('conversation_inactive', 'This conversation is not active.');
        }

        [$p1, $p2] = Conversation::normalizePairIds($sender->id, $receiver->id);
        if ((int) $conversation->profile_one_id !== $p1 || (int) $conversation->profile_two_id !== $p2) {
            return PolicyDecision::deny('not_participant', 'You cannot send messages in this conversation.');
        }

        $cfg = CommunicationPolicyService::getConfig();
        $now = now();

        // Rolling windows for sender usage limits
        $dailyMax = (int) ($cfg['max_messages_per_day_per_sender'] ?? 20);
        $weeklyMax = (int) ($cfg['max_messages_per_week_per_sender'] ?? 100);
        $monthlyMax = (int) ($cfg['max_messages_per_month_per_sender'] ?? 300);

        if ($dailyMax > 0) {
            $c = Message::where('sender_profile_id', $sender->id)->where('sent_at', '>=', $now->copy()->subDay())->count();
            if ($c >= $dailyMax) {
                return PolicyDecision::deny('daily_limit', 'Daily message limit reached.');
            }
        }
        if ($weeklyMax > 0) {
            $c = Message::where('sender_profile_id', $sender->id)->where('sent_at', '>=', $now->copy()->subDays(7))->count();
            if ($c >= $weeklyMax) {
                return PolicyDecision::deny('weekly_limit', 'Weekly message limit reached.');
            }
        }
        if ($monthlyMax > 0) {
            $c = Message::where('sender_profile_id', $sender->id)->where('sent_at', '>=', $now->copy()->subDays(30))->count();
            if ($c >= $monthlyMax) {
                return PolicyDecision::deny('monthly_limit', 'Monthly message limit reached.');
            }
        }

        $latestCooldown = MessagePolicyCooldown::query()
            ->where('sender_profile_id', $sender->id)
            ->where('receiver_profile_id', $receiver->id)
            ->where('reason', MessagePolicyCooldown::REASON_REPLY_GATE_LIMIT)
            ->orderByDesc('locked_until')
            ->first();

        if ($latestCooldown && $latestCooldown->locked_until && $latestCooldown->locked_until->isFuture()) {
            return PolicyDecision::deny(
                'reply_gate_cooldown',
                'तुम्ही सलग मर्यादित संदेश पाठवले आहेत. समोरील व्यक्तीचा reply येईपर्यंत किंवा cooling period संपेपर्यंत पुन्हा संदेश पाठवता येणार नाही.',
                $latestCooldown->locked_until
            );
        }

        $mode = (string) ($cfg['messaging_mode'] ?? 'free_chat_with_reply_gate');
        if ($mode === 'free_chat_with_reply_gate') {
            $maxConsecutive = (int) ($cfg['max_consecutive_messages_without_reply'] ?? 2);
            // After a cooling period ends, allow a fresh quota of N messages.
            $cycleBoundary = $latestCooldown?->locked_until;
            $consecutive = $this->countTrailingConsecutiveUnreplied($conversation->id, $sender->id, $receiver->id, $cycleBoundary);
            if ($consecutive >= $maxConsecutive) {
                return PolicyDecision::deny(
                    'reply_gate_limit',
                    'तुम्ही सलग मर्यादित संदेश पाठवले आहेत. समोरील व्यक्तीचा reply येईपर्यंत किंवा cooling period संपेपर्यंत पुन्हा संदेश पाठवता येणार नाही.'
                );
            }
        }

        return PolicyDecision::allow($access->meta);
    }

    public function registerReplyGateLockIfNeeded(MatrimonyProfile $sender, MatrimonyProfile $receiver, Conversation $conversation): void
    {
        $cfg = CommunicationPolicyService::getConfig();
        $mode = (string) ($cfg['messaging_mode'] ?? 'free_chat_with_reply_gate');
        if ($mode !== 'free_chat_with_reply_gate') {
            return;
        }

        $maxConsecutive = (int) ($cfg['max_consecutive_messages_without_reply'] ?? 2);
        $latestCooldown = MessagePolicyCooldown::query()
            ->where('sender_profile_id', $sender->id)
            ->where('receiver_profile_id', $receiver->id)
            ->where('reason', MessagePolicyCooldown::REASON_REPLY_GATE_LIMIT)
            ->orderByDesc('locked_until')
            ->first();

        $cycleBoundary = $latestCooldown?->locked_until;
        $consecutive = $this->countTrailingConsecutiveUnreplied($conversation->id, $sender->id, $receiver->id, $cycleBoundary);
        if ($consecutive < $maxConsecutive) {
            return;
        }

        $hours = (int) ($cfg['reply_gate_cooling_hours'] ?? 24);
        $lockedUntil = now()->addHours(max(1, $hours));

        MessagePolicyCooldown::updateOrCreate(
            [
                'sender_profile_id' => $sender->id,
                'receiver_profile_id' => $receiver->id,
                'reason' => MessagePolicyCooldown::REASON_REPLY_GATE_LIMIT,
            ],
            [
                'conversation_id' => $conversation->id,
                'locked_until' => $lockedUntil,
            ]
        );
    }

    public function clearReplyGateOnReply(MatrimonyProfile $replier, MatrimonyProfile $otherParticipant, Conversation $conversation): void
    {
        MessagePolicyCooldown::query()
            ->where('sender_profile_id', $otherParticipant->id)
            ->where('receiver_profile_id', $replier->id)
            ->where('reason', MessagePolicyCooldown::REASON_REPLY_GATE_LIMIT)
            ->delete();
    }

    protected function isPairBlocked(int $aProfileId, int $bProfileId): bool
    {
        return Block::where('blocker_profile_id', $aProfileId)->where('blocked_profile_id', $bProfileId)->exists()
            || Block::where('blocker_profile_id', $bProfileId)->where('blocked_profile_id', $aProfileId)->exists();
    }

    /**
     * Count trailing consecutive messages in conversation from sender, until receiver's first message encountered.
     */
    protected function countTrailingConsecutiveUnreplied(int $conversationId, int $senderId, int $receiverId, ?\DateTimeInterface $since = null): int
    {
        $rows = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->when($since !== null, fn ($qb) => $qb->where('sent_at', '>', $since))
            ->orderByDesc('sent_at')
            ->limit(50)
            ->get(['sender_profile_id']);

        $count = 0;
        foreach ($rows as $r) {
            $sid = (int) $r->sender_profile_id;
            if ($sid === $senderId) {
                $count++;
                continue;
            }
            if ($sid === $receiverId) {
                break;
            }
            break;
        }
        return $count;
    }
}

