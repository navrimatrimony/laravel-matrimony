<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewChatMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MatrimonyProfile $senderProfile,
        public int $conversationId,
        public string $messageType,
        public ?string $messagePreview,
        public int $messageId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->senderProfile->full_name ?? 'Someone';
        $preview = trim((string) ($this->messagePreview ?? ''));
        $preview = $preview !== '' ? Str::limit($preview, 80) : '';

        $display = $this->messageType === 'image'
            ? ($preview !== '' ? ('📷 ' . $preview) : '📷 Image')
            : $preview;

        return [
            'type' => 'chat_message',
            'message' => "{$name} sent you a message.",
            'sender_name' => $name,
            'sender_profile_id' => $this->senderProfile->id,
            'conversation_id' => $this->conversationId,
            'message_preview' => $display,
            'message_id' => $this->messageId,
        ];
    }
}

