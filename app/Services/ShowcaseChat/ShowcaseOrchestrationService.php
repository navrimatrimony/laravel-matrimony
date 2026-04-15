<?php

namespace App\Services\ShowcaseChat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\ShowcaseChatSetting;
use App\Models\ShowcaseConversationState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ShowcaseOrchestrationService
{
    public function __construct(
        protected ShowcaseChatSettingsService $settingsService,
        protected ShowcasePresenceService $presence,
        protected ShowcaseReadSchedulerService $readScheduler,
        protected ShowcaseReplySchedulerService $replyScheduler,
        protected ShowcaseReplyExecutionService $replyExecutor,
    ) {}

    /**
     * Runtime-only debug snapshot for admin (nothing persisted).
     */
    public function buildDebugSnapshot(ShowcaseConversationState $state, ShowcaseChatSetting $setting): array
    {
        $now = Carbon::now();
        $conversation = Conversation::find($state->conversation_id);
        $showcase = MatrimonyProfile::find($state->showcase_profile_id);
        if (! $conversation || ! $showcase) {
            return [
                'profile_id' => $state->showcase_profile_id,
                'conversation_id' => $state->conversation_id,
                'error' => 'Conversation or showcase profile not found.',
            ];
        }

        $prevIncoming = $this->getPreviousIncomingMessageTimeForShowcase($conversation, $showcase);
        $breakdown = $this->replyScheduler->computeProbabilityBreakdown(
            (int) ($setting->reply_probability_percent ?? 0),
            (int) ($state->unanswered_incoming_count ?? 0),
            $prevIncoming,
            $now,
            (string) ($setting->personality_preset ?? 'balanced')
        );

        $lockUntil = $state->active_lock_until;
        $isActiveLock = $lockUntil && $lockUntil->isFuture();

        $reasons = [];
        if (! $setting->enabled) {
            $reasons[] = 'Orchestration disabled in settings.';
        }
        if ($setting->is_paused) {
            $reasons[] = 'Settings marked paused.';
        }
        if ($this->isAutomationBlocked($state, $setting)) {
            $reasons[] = 'Automation blocked (takeover / paused / silenced).';
        }
        if (! $this->presence->canActNow($setting, 'reply')) {
            $reasons[] = 'Reply not allowed now (business hours / off-hours policy).';
        }
        if ($this->hasOtherActiveLock($showcase->id, $conversation->id, $now)) {
            $reasons[] = 'Another conversation holds the active lock for this showcase.';
        }
        $priorityId = $this->choosePriorityConversationId($showcase->id, $now);
        if ($priorityId !== null && (int) $priorityId !== (int) $conversation->id) {
            $reasons[] = 'Not the current priority conversation for automated replies.';
        }
        if ($breakdown['blocked_by_unanswered_cap']) {
            $reasons[] = 'Unanswered incoming cap (6+) reached.';
        }
        if ($breakdown['final_probability'] <= 0) {
            $reasons[] = 'Final reply probability is 0.';
        }

        $canReply = $reasons === [] && $breakdown['final_probability'] > 0;

        return [
            'profile_id' => $showcase->id,
            'conversation_id' => $conversation->id,
            'is_active_lock' => $isActiveLock,
            'active_lock_until' => $lockUntil?->toIso8601String(),
            'pending_read_at' => $state->pending_read_at?->toIso8601String(),
            'pending_typing_at' => $state->pending_typing_at?->toIso8601String(),
            'pending_reply_at' => $state->pending_reply_at?->toIso8601String(),
            'pending_offline_at' => $state->pending_offline_at?->toIso8601String(),
            'unanswered_incoming_count' => (int) ($state->unanswered_incoming_count ?? 0),
            'last_incoming_at' => $state->last_incoming_at?->toIso8601String(),
            'base_probability' => $breakdown['base_probability'],
            'fatigue_penalty' => $breakdown['fatigue_penalty'],
            'spam_penalty' => $breakdown['spam_penalty'],
            'personality_modifier' => $breakdown['personality_modifier'],
            'final_probability' => $breakdown['final_probability'],
            'blocked_by_unanswered_cap' => $breakdown['blocked_by_unanswered_cap'],
            'can_reply_now' => $canReply,
            'blocked_reason' => $canReply ? null : implode(' ', $reasons),
        ];
    }

    protected function getPreviousIncomingMessageTimeForShowcase(Conversation $conversation, MatrimonyProfile $showcase): ?Carbon
    {
        $row = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('receiver_profile_id', $showcase->id)
            ->orderByDesc('sent_at')
            ->skip(1)
            ->first();

        return $row?->sent_at;
    }

    public function ensureState(Conversation $conversation, MatrimonyProfile $showcase): ShowcaseConversationState
    {
        return ShowcaseConversationState::firstOrCreate(
            [
                'conversation_id' => $conversation->id,
                'showcase_profile_id' => $showcase->id,
            ],
            [
                'automation_status' => ShowcaseConversationState::STATUS_ACTIVE,
            ]
        );
    }

    /**
     * After showcase sends any outbound text (auto or admin), mirror the same side effects as the
     * automated tick path: mark user→showcase messages read, reset fatigue, clear pending read/typing/reply, linger + lock.
     */
    public function applyShowcaseOutgoingMessageEffects(
        Conversation $conversation,
        MatrimonyProfile $showcase,
        Message $sentMessage,
        bool $automatedReply
    ): void {
        if ((int) $sentMessage->sender_profile_id !== (int) $showcase->id) {
            return;
        }

        $setting = $this->settingsService->getOrCreateForProfile($showcase);
        $state = $this->ensureState($conversation, $showcase);
        $state->refresh();

        $this->markReadAsShowcase($showcase, $conversation);

        $now = now();
        $state->pending_read_at = null;
        $state->pending_typing_at = null;
        $state->pending_reply_at = null;
        $state->unanswered_incoming_count = 0;
        $state->last_read_at = $now;
        $state->last_outgoing_message_id = $sentMessage->id;
        if ($automatedReply) {
            $state->last_auto_reply_at = $now;
        }

        $linger = $this->randSeconds(
            (int) $setting->online_linger_after_reply_min_seconds,
            (int) $setting->online_linger_after_reply_max_seconds
        );
        $state->pending_offline_at = $now->copy()->addSeconds($linger);
        $this->acquireActiveLockForState($state, $state->pending_offline_at);
        $state->save();
    }

    public function onIncomingMessage(Message $incoming): void
    {
        $incoming->loadMissing(['conversation', 'senderProfile', 'receiverProfile']);

        $receiver = $incoming->receiverProfile;
        if (! $receiver || ! $receiver->isShowcaseProfile()) {
            return;
        }

        $setting = $this->settingsService->getOrCreateForProfile($receiver);
        if (! $setting->enabled || $setting->is_paused) {
            return;
        }

        // Always schedule read first; if read scheduling is not allowed, do not schedule reply.
        if (! $this->presence->canActNow($setting, 'read')) {
            return;
        }

        $conv = $incoming->conversation;
        if (! $conv) {
            return;
        }

        $state = $this->ensureState($conv, $receiver);
        if ($this->isAutomationBlocked($state, $setting)) {
            return;
        }

        $previousIncomingAt = $state->last_incoming_at;

        // Update fatigue counters.
        $state->unanswered_incoming_count = (int) ($state->unanswered_incoming_count ?? 0) + 1;
        $state->last_incoming_at = now();

        // Avoid stacking multiple reply chains; if a reply is already scheduled in the future, keep it.
        $hasFutureReply = $state->pending_reply_at && $state->pending_reply_at->isFuture();
        if (! $hasFutureReply) {
            $state->pending_typing_at = null;
            $state->pending_reply_at = null;
        }
        $state->save();

        // Schedule read (not instant by default)
        $this->readScheduler->scheduleRead($state, $incoming, (int) $setting->read_delay_min_minutes, (int) $setting->read_delay_max_minutes);

        // If read scheduling did not set a pending read time, do not schedule reply.
        $state->refresh();
        if (! $state->pending_read_at) {
            return;
        }

        // Single-active-conversation control: if another conversation is locked active, queue this one (read allowed, reply blocked).
        if ($this->hasOtherActiveLock($receiver->id, $conv->id, $now = Carbon::now())) {
            return;
        }

        // Priority: if multiple conversations compete and none is locked, only the best candidate gets to schedule replies.
        $priorityConversationId = $this->choosePriorityConversationId($receiver->id, $now);
        if ($priorityConversationId && (int) $priorityConversationId !== (int) $conv->id) {
            return;
        }

        // Decide + schedule reply
        if ($hasFutureReply) {
            return;
        }

        if ($this->presence->canActNow($setting, 'reply') && $this->replyScheduler->shouldReplyToIncoming($setting, $incoming, $state, $previousIncomingAt)) {
            $this->replyScheduler->scheduleTypingAndReply($state, $setting, $state->pending_read_at);
            $state->refresh();
            $this->acquireActiveLockForState($state, $state->pending_reply_at);
        }
    }

    public function tickConversation(Conversation $conversation, MatrimonyProfile $showcase, MatrimonyProfile $realUser): array
    {
        $setting = $this->settingsService->getOrCreateForProfile($showcase);
        if (! $setting->enabled) {
            return ['online' => false, 'typing' => false];
        }

        $state = $this->ensureState($conversation, $showcase);

        $this->presence->closeDueSessions();

        $now = now();
        $online = $this->presence->isOnline($showcase, $conversation);

        // If a read is due, ensure online then read.
        if ($state->pending_read_at && $state->pending_read_at->lessThanOrEqualTo($now) && $this->presence->canActNow($setting, 'read')) {
            if (! $online) {
                $mins = $this->randMinutes((int) $setting->online_session_min_minutes, (int) $setting->online_session_max_minutes);
                $this->presence->openOnlineSession($showcase, $setting, $conversation, 'incoming_message', $mins);
                $online = true;
                $state->last_online_at = $now;
            }

            $waitSec = $this->randSeconds((int) $setting->online_before_read_min_seconds, (int) $setting->online_before_read_max_seconds);
            if ($state->last_online_at && $state->last_online_at->diffInSeconds($now) < $waitSec) {
                // Keep it pending until online long enough
            } else {
                $this->markReadAsShowcase($showcase, $conversation);
                $state->pending_read_at = null;
                $state->last_read_at = $now;
                $state->save();
            }
        }

        // Determine typing window (typing shown only if online).
        $typing = false;
        if ($online && $state->pending_typing_at && $state->pending_reply_at) {
            $typing = $now->greaterThanOrEqualTo($state->pending_typing_at) && $now->lessThan($state->pending_reply_at);
        }

        // If reply is due, ensure online then send.
        if ($state->pending_reply_at && $state->pending_reply_at->lessThanOrEqualTo($now) && $this->presence->canActNow($setting, 'reply')) {
            // Single-active-conversation control: only the active conversation may reply.
            if ($this->hasOtherActiveLock($showcase->id, $conversation->id, $now)) {
                return [
                    'online' => $this->presence->isOnline($showcase, $conversation),
                    'typing' => false,
                ];
            }

            // If no active lock exists, only the priority conversation may claim it and reply.
            if (! $this->hasAnyActiveLock($showcase->id, $now)) {
                $priorityConversationId = $this->choosePriorityConversationId($showcase->id, $now);
                if ($priorityConversationId && (int) $priorityConversationId !== (int) $conversation->id) {
                    return [
                        'online' => $this->presence->isOnline($showcase, $conversation),
                        'typing' => false,
                    ];
                }
                $this->acquireActiveLockForState($state, $state->pending_reply_at);
            }

            // Safety: do not reply before a read has occurred when a pending read was expected.
            if (! $state->last_read_at) {
                if ($state->pending_read_at || $state->last_incoming_message_id) {
                    return [
                        'online' => $this->presence->isOnline($showcase, $conversation),
                        'typing' => false,
                    ];
                }
            }

            if (! $online) {
                $mins = $this->randMinutes((int) $setting->online_session_min_minutes, (int) $setting->online_session_max_minutes);
                $this->presence->openOnlineSession($showcase, $setting, $conversation, 'reply_flow', $mins);
                $online = true;
                $state->last_online_at = $now;
            }

            $incomingText = $this->getLastIncomingTextForShowcase($conversation, $showcase);
            $text = $this->replyExecutor->buildAutoReplyText($incomingText, $setting, (int) $conversation->id);

            try {
                $msg = $this->replyExecutor->sendShowcaseTextReply($showcase, $realUser, $conversation, $text);
                $this->applyShowcaseOutgoingMessageEffects($conversation, $showcase, $msg, true);
                $state->refresh();
            } catch (\Throwable $e) {
                // If policy blocks, silently stop automation for now.
                $state->refresh();
                $state->automation_status = ShowcaseConversationState::STATUS_SILENCED;
                $state->pending_reply_at = null;
                $state->pending_typing_at = null;
                $state->save();
            }
        }

        // Offline if linger window passed (optional; sessions auto-close too)
        if ($state->pending_offline_at && $state->pending_offline_at->lessThanOrEqualTo($now)) {
            $state->pending_offline_at = null;
            $state->last_offline_at = $now;
            $state->active_lock_until = null;
            $state->save();
        }

        return [
            'online' => $this->presence->isOnline($showcase, $conversation),
            'typing' => $typing && $this->presence->isOnline($showcase, $conversation),
        ];
    }

    protected function markReadAsShowcase(MatrimonyProfile $showcase, Conversation $conversation): void
    {
        // Minimal: mark as read directly in messages table for showcase receiver only.
        // Do not touch profile SSOT.
        DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->where('receiver_profile_id', $showcase->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'delivery_status' => 'read',
                'updated_at' => now(),
            ]);

        // Also keep participant state consistent.
        $latest = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('sent_at')
            ->first();

        if ($latest) {
            DB::table('message_participant_states')
                ->updateOrInsert(
                    ['conversation_id' => $conversation->id, 'profile_id' => $showcase->id],
                    [
                        'last_read_message_id' => $latest->id,
                        'last_read_at' => now(),
                        'is_archived' => false,
                        'is_blocked' => false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
        }
    }

    protected function getLastIncomingTextForShowcase(Conversation $conversation, MatrimonyProfile $showcase): ?string
    {
        $m = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('receiver_profile_id', $showcase->id)
            ->orderByDesc('sent_at')
            ->first();

        return $m?->body_text;
    }

    protected function isAutomationBlocked(ShowcaseConversationState $state, ShowcaseChatSetting $setting): bool
    {
        if ($setting->is_paused) {
            return true;
        }
        if ($state->automation_status === ShowcaseConversationState::STATUS_ADMIN_TAKEOVER) {
            if ($state->admin_takeover_until && $state->admin_takeover_until->isFuture()) {
                return true;
            }
        }
        if ($state->automation_status === ShowcaseConversationState::STATUS_PAUSED || $state->automation_status === ShowcaseConversationState::STATUS_SILENCED) {
            return true;
        }

        return false;
    }

    protected function randMinutes(int $min, int $max): int
    {
        $min = max(1, $min);
        $max = max($min, $max);
        return random_int($min, $max);
    }

    protected function randSeconds(int $min, int $max): int
    {
        $min = max(1, $min);
        $max = max($min, $max);
        return random_int($min, $max);
    }

    protected function hasAnyActiveLock(int $showcaseProfileId, Carbon $now): bool
    {
        return ShowcaseConversationState::query()
            ->where('showcase_profile_id', $showcaseProfileId)
            ->whereNotNull('active_lock_until')
            ->where('active_lock_until', '>', $now)
            ->exists();
    }

    protected function hasOtherActiveLock(int $showcaseProfileId, int $conversationId, Carbon $now): bool
    {
        return ShowcaseConversationState::query()
            ->where('showcase_profile_id', $showcaseProfileId)
            ->where('conversation_id', '!=', $conversationId)
            ->whereNotNull('active_lock_until')
            ->where('active_lock_until', '>', $now)
            ->exists();
    }

    protected function choosePriorityConversationId(int $showcaseProfileId, Carbon $now): ?int
    {
        $states = ShowcaseConversationState::query()
            ->where('showcase_profile_id', $showcaseProfileId)
            ->whereIn('automation_status', [
                ShowcaseConversationState::STATUS_ACTIVE,
                ShowcaseConversationState::STATUS_ADMIN_TAKEOVER,
            ])
            ->where(function ($q) {
                $q->whereNotNull('pending_reply_at')
                    ->orWhereNotNull('pending_read_at');
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('active_lock_until')
                    ->orWhere('active_lock_until', '<=', $now);
            })
            ->get();

        if ($states->isEmpty()) {
            return null;
        }

        $sorted = $states->sort(function (ShowcaseConversationState $a, ShowcaseConversationState $b) use ($now) {
            $aHasReply = $a->pending_reply_at ? 0 : 1;
            $bHasReply = $b->pending_reply_at ? 0 : 1;
            if ($aHasReply !== $bHasReply) {
                return $aHasReply <=> $bHasReply;
            }

            $aRead = $a->pending_read_at ? $a->pending_read_at->timestamp : PHP_INT_MAX;
            $bRead = $b->pending_read_at ? $b->pending_read_at->timestamp : PHP_INT_MAX;
            if ($aRead !== $bRead) {
                return $aRead <=> $bRead;
            }

            $aFatigue = (int) ($a->unanswered_incoming_count ?? 0);
            $bFatigue = (int) ($b->unanswered_incoming_count ?? 0);
            if ($aFatigue !== $bFatigue) {
                return $aFatigue <=> $bFatigue;
            }

            return (int) $a->conversation_id <=> (int) $b->conversation_id;
        });

        $best = $sorted->first();
        return $best ? (int) $best->conversation_id : null;
    }

    protected function acquireActiveLockForState(ShowcaseConversationState $state, ?Carbon $until): void
    {
        if (! $until) {
            return;
        }

        $now = Carbon::now();
        $bufferMinutes = random_int(1, 3);
        $lockUntil = $until->copy()->addMinutes($bufferMinutes);
        if ($lockUntil->lessThanOrEqualTo($now)) {
            $lockUntil = $now->copy()->addMinutes(1);
        }

        // Safety: ensure only one active lock exists per showcase profile.
        ShowcaseConversationState::query()
            ->where('showcase_profile_id', $state->showcase_profile_id)
            ->where('conversation_id', '!=', $state->conversation_id)
            ->whereNotNull('active_lock_until')
            ->where('active_lock_until', '>', $now)
            ->update(['active_lock_until' => null]);

        $state->active_lock_until = $lockUntil;
        $state->save();
    }

    public function processDueEvents(): int
    {
        $processed = 0;

        $states = ShowcaseConversationState::query()
            ->whereIn('automation_status', [
                ShowcaseConversationState::STATUS_ACTIVE,
                ShowcaseConversationState::STATUS_ADMIN_TAKEOVER,
            ])
            ->get();

        foreach ($states as $state) {
            $conversation = Conversation::find($state->conversation_id);
            $showcase = MatrimonyProfile::find($state->showcase_profile_id);

            if (! $conversation || ! $showcase) {
                continue;
            }

            $realUser = MatrimonyProfile::query()
                ->whereIn('id', function ($q) use ($conversation) {
                    $q->select('sender_profile_id')
                        ->from('messages')
                        ->where('conversation_id', $conversation->id)
                        ->union(
                            DB::table('messages')
                                ->select('receiver_profile_id')
                                ->where('conversation_id', $conversation->id)
                        );
                })
                ->where('id', '!=', $showcase->id)
                ->first();

            if (! $realUser) {
                continue;
            }

            $this->tickConversation($conversation, $showcase, $realUser);

            $processed++;
        }

        return $processed;
    }
}

