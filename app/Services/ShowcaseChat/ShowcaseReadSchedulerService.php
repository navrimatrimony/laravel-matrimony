<?php

namespace App\Services\ShowcaseChat;

use App\Models\Message;
use App\Models\ShowcaseConversationState;
use Carbon\Carbon;

class ShowcaseReadSchedulerService
{
    public function scheduleRead(ShowcaseConversationState $state, Message $incoming, int $minMinutes, int $maxMinutes): ShowcaseConversationState
    {
        $minMinutes = max(1, $minMinutes);
        $maxMinutes = max($minMinutes, $maxMinutes);

        $delay = random_int($minMinutes, $maxMinutes);
        $when = Carbon::now()->addMinutes($delay);

        // Never schedule instant read; enforce at least +1 minute via $minMinutes default.
        $state->pending_read_at = $when;
        $state->last_incoming_message_id = $incoming->id;
        $state->save();

        return $state;
    }
}

