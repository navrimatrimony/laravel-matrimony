<?php

namespace App\Services\ShowcaseChat;

use App\Models\Message;
use App\Models\ShowcaseChatSetting;
use App\Models\ShowcaseConversationState;
use Carbon\Carbon;

class ShowcaseReplySchedulerService
{
    public function __construct(
        protected ShowcaseProbabilityDecisionService $probability,
    ) {}

    public function shouldReplyToIncoming(ShowcaseChatSetting $s, Message $incoming, ShowcaseConversationState $state, ?Carbon $previousIncomingAt = null): bool
    {
        $now = Carbon::now();
        $final = $this->computeFinalProbabilityPercent(
            basePercent: (int) ($s->reply_probability_percent ?? 0),
            unansweredIncomingCount: (int) ($state->unanswered_incoming_count ?? 0),
            previousIncomingAt: $previousIncomingAt,
            now: $now,
            personalityPreset: (string) ($s->personality_preset ?? 'balanced')
        );

        if ($final <= 0) {
            return false;
        }
        if ($final >= 100) {
            return true;
        }

        return random_int(1, 100) <= $final;
    }

    /**
     * Single source of truth for probability math (same rules as {@see computeFinalProbabilityPercent}).
     *
     * @return array{
     *   base_probability:int,
     *   fatigue_penalty:int,
     *   spam_penalty:int,
     *   personality_modifier:int,
     *   final_probability:int,
     *   blocked_by_unanswered_cap:bool
     * }
     */
    public function computeProbabilityBreakdown(int $basePercent, int $unansweredIncomingCount, ?Carbon $previousIncomingAt, Carbon $now, ?string $personalityPreset = null): array
    {
        $base = max(0, min(100, $basePercent));

        if ($unansweredIncomingCount >= 6) {
            return [
                'base_probability' => $base,
                'fatigue_penalty' => 0,
                'spam_penalty' => 0,
                'personality_modifier' => 0,
                'final_probability' => 0,
                'blocked_by_unanswered_cap' => true,
            ];
        }

        $fatigue = 0;
        if ($unansweredIncomingCount === 1) {
            $fatigue += 10;
        }
        if ($unansweredIncomingCount >= 4) {
            $fatigue -= 40;
        } elseif ($unansweredIncomingCount >= 2) {
            $fatigue -= 20;
        }

        $spam = 0;
        if ($previousIncomingAt) {
            $gapSeconds = $previousIncomingAt->diffInSeconds($now);
            if ($gapSeconds < 120) {
                $spam = -30;
            }
        }

        $personality = ShowcaseChatSettingsService::personalityReplyProbabilityModifier(
            (string) ($personalityPreset ?? 'balanced')
        );

        $final = max(0, min(100, (int) ($base + $fatigue + $spam + $personality)));

        return [
            'base_probability' => $base,
            'fatigue_penalty' => $fatigue,
            'spam_penalty' => $spam,
            'personality_modifier' => $personality,
            'final_probability' => $final,
            'blocked_by_unanswered_cap' => false,
        ];
    }

    public function computeFinalProbabilityPercent(int $basePercent, int $unansweredIncomingCount, ?Carbon $previousIncomingAt, Carbon $now, ?string $personalityPreset = null): int
    {
        return $this->computeProbabilityBreakdown($basePercent, $unansweredIncomingCount, $previousIncomingAt, $now, $personalityPreset)['final_probability'];
    }

    public function scheduleTypingAndReply(ShowcaseConversationState $state, ShowcaseChatSetting $s, ?Carbon $baseTime = null): ShowcaseConversationState
    {
        $now = Carbon::now();

        $readAt = $baseTime ?: ($state->pending_read_at ?: $state->last_read_at ?: $now);
        if ($readAt->lessThan($now)) {
            // Keep base in present/future so typing/reply never schedules in the past.
            $readAt = $now;
        }

        $afterReadMin = max(0, (int) ($s->reply_after_read_min_minutes ?? 0));
        $afterReadMax = max($afterReadMin, (int) ($s->reply_after_read_max_minutes ?? $afterReadMin));
        $afterReadDelay = random_int($afterReadMin, $afterReadMax);

        $replyAtC = $readAt->copy()->addMinutes($afterReadDelay);
        if ($replyAtC->lessThanOrEqualTo($readAt)) {
            $replyAtC = $readAt->copy()->addMinute();
        }

        $typingEnabled = (bool) ($s->typing_enabled ?? true);
        if ($typingEnabled) {
            $durMin = max(1, (int) ($s->typing_duration_min_seconds ?? 1));
            $durMax = max($durMin, (int) ($s->typing_duration_max_seconds ?? $durMin));
            $typingDur = random_int($durMin, $durMax);
            $typingAt = $replyAtC->copy()->subSeconds($typingDur);
            if ($typingAt->lessThan($readAt)) {
                $typingAt = $readAt->copy();
            }
            if ($typingAt->greaterThanOrEqualTo($replyAtC)) {
                $typingAt = $replyAtC->copy()->subSecond();
            }
            $state->pending_typing_at = $typingAt;
        } else {
            $state->pending_typing_at = null;
        }

        $state->pending_reply_at = $replyAtC;
        $state->save();

        return $state;
    }
}

