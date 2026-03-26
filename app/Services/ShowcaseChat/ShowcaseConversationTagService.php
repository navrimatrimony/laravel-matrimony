<?php

namespace App\Services\ShowcaseChat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;

class ShowcaseConversationTagService
{
    public const TAG_TEXT = 'AI Assisted Replies';

    public function __construct(
        protected ShowcaseChatSettingsService $settings,
    ) {}

    public function shouldShowTagForConversation(Conversation $conversation, MatrimonyProfile $viewer, ?MatrimonyProfile $other): bool
    {
        if (!$other) {
            return false;
        }
        if (!($other->is_demo ?? false)) {
            return false;
        }

        $s = $this->settings->getOrCreateForProfile($other);
        return (bool) ($s->enabled && $s->ai_assisted_replies_enabled);
    }
}

