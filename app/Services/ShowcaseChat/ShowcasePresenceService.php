<?php

namespace App\Services\ShowcaseChat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\ShowcaseChatSetting;
use App\Models\ShowcasePresenceSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShowcasePresenceService
{
    public function isOnline(MatrimonyProfile $showcase, ?Conversation $conversation = null): bool
    {
        $q = ShowcasePresenceSession::query()
            ->where('showcase_profile_id', $showcase->id)
            ->whereNull('ended_at')
            ->where('started_at', '<=', now())
            ->where(function ($qb) {
                $qb->whereNull('scheduled_end_at')->orWhere('scheduled_end_at', '>=', now());
            });

        if ($conversation) {
            $q->where(function ($qb) use ($conversation) {
                $qb->whereNull('conversation_id')->orWhere('conversation_id', $conversation->id);
            });
        }

        return $q->exists();
    }

    public function canActNow(ShowcaseChatSetting $s, string $kind): bool
    {
        // $kind: online|read|reply
        if (! $s->enabled || $s->is_paused) {
            return false;
        }

        if ($s->business_hours_enabled) {
            if (empty($s->business_hours_start) || empty($s->business_hours_end)) {
                return true;
            }
        }

        $inBusinessHours = $this->isWithinBusinessHours($s, now());
        if ($inBusinessHours) {
            return true;
        }

        if ($kind === 'online') {
            return (bool) $s->off_hours_online_allowed;
        }
        if ($kind === 'read') {
            return (bool) $s->off_hours_read_allowed;
        }
        if ($kind === 'reply') {
            return (bool) $s->off_hours_reply_allowed;
        }

        return false;
    }

    public function openOnlineSession(MatrimonyProfile $showcase, ShowcaseChatSetting $s, ?Conversation $conversation, string $triggerType, int $minutes): ShowcasePresenceSession
    {
        $minutes = max(1, $minutes);
        $now = now();
        $end = $now->copy()->addMinutes($minutes);

        return ShowcasePresenceSession::create([
            'showcase_profile_id' => $showcase->id,
            'conversation_id' => $conversation?->id,
            'started_at' => $now,
            'scheduled_end_at' => $end,
            'ended_at' => null,
            'trigger_type' => $triggerType,
        ]);
    }

    public function closeDueSessions(): int
    {
        return (int) DB::table('showcase_presence_sessions')
            ->whereNull('ended_at')
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_end_at', '<=', now())
            ->update([
                'ended_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function isWithinBusinessHours(ShowcaseChatSetting $s, Carbon $now): bool
    {
        if (! $s->business_hours_enabled) {
            return true;
        }

        $days = $s->business_days_json;
        if (is_array($days) && ! empty($days)) {
            // ISO-8601 1..7 (Mon..Sun)
            $dow = (int) $now->dayOfWeekIso;
            if (! in_array($dow, array_map('intval', $days), true)) {
                return false;
            }
        }

        if ($s->business_hours_start && $s->business_hours_end) {
            $start = Carbon::parse($s->business_hours_start, $now->timezone)->setDateFrom($now);
            $end = Carbon::parse($s->business_hours_end, $now->timezone)->setDateFrom($now);
            if ($end->lessThanOrEqualTo($start)) {
                // Overnight window (e.g. 22:00-06:00)
                return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
            }
            return $now->between($start, $end);
        }

        return true;
    }
}

