<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\ChatMessageLockedNotification;
use Tests\TestCase;

class ChatMessageLockedNotificationTest extends TestCase
{
    public function test_payload_has_no_identity_or_conversation_leak(): void
    {
        $user = new User;
        $n = new ChatMessageLockedNotification;
        $payload = $n->toArray($user);

        $this->assertSame('chat_message_locked', $payload['type']);
        $this->assertFalse($payload['revealed']);
        $this->assertArrayNotHasKey('sender_profile_id', $payload);
        $this->assertArrayNotHasKey('conversation_id', $payload);
        $this->assertArrayNotHasKey('sender_name', $payload);
        $this->assertSame(__('notifications.chat_locked_message_anonymous'), $payload['message']);
    }
}
