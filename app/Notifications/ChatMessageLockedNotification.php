<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChatMessageLockedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MatrimonyProfile $senderProfile,
        public int $conversationId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->senderProfile->full_name ?? 'Someone';

        return [
            'type' => 'chat_message_locked',
            'sender_profile_id' => $this->senderProfile->id,
            'conversation_id' => $this->conversationId,
            'message' => __('notifications.chat_locked_message', ['name' => $name]),
        ];
    }
}
