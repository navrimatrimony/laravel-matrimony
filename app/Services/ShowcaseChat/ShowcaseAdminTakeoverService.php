<?php

namespace App\Services\ShowcaseChat;

use App\Models\ShowcaseAdminAction;
use App\Models\ShowcaseConversationState;
use App\Models\User;

class ShowcaseAdminTakeoverService
{
    public function pauseAutomationForConversation(ShowcaseConversationState $state, User $admin, string $notes = ''): void
    {
        $state->automation_status = ShowcaseConversationState::STATUS_ADMIN_TAKEOVER;
        $state->admin_takeover_until = now()->addMinutes(30);
        $state->pending_read_at = null;
        $state->pending_typing_at = null;
        $state->pending_reply_at = null;
        $state->pending_offline_at = null;
        $state->save();

        ShowcaseAdminAction::create([
            'admin_user_id' => $admin->id,
            'showcase_profile_id' => $state->showcase_profile_id,
            'conversation_id' => $state->conversation_id,
            'action_type' => 'admin_takeover',
            'notes' => $notes !== '' ? $notes : null,
        ]);
    }

    public function resumeAutomationForConversation(ShowcaseConversationState $state, User $admin, string $notes = ''): void
    {
        $state->automation_status = ShowcaseConversationState::STATUS_ACTIVE;
        $state->admin_takeover_until = null;
        $state->save();

        ShowcaseAdminAction::create([
            'admin_user_id' => $admin->id,
            'showcase_profile_id' => $state->showcase_profile_id,
            'conversation_id' => $state->conversation_id,
            'action_type' => 'resume',
            'notes' => $notes !== '' ? $notes : null,
        ]);
    }
}

