<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Day-32 Step 7: Notify receiver when someone sends a contact request.
 */
class ContactRequestReceivedNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public ContactRequest $contactRequest
    ) {}

    public function via(object $notifiable): array
    {
        return $this->matrimonyNotificationChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->matrimonyMailFromPayload($this->toArray($notifiable), $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        $sender = $this->contactRequest->sender;
        $name = trim((string) ($sender?->matrimonyProfile?->full_name ?? $sender?->name ?? ''));
        if ($name === '') {
            $name = __('notifications.someone');
        }

        return NotificationMarathiPayload::withMessage([
            'type' => 'contact_request_received',
            'message' => __('notifications.contact_request_received_message', ['name' => $name]),
            'contact_request_id' => $this->contactRequest->id,
            'sender_id' => $this->contactRequest->sender_id,
            'sender_profile_id' => (int) ($sender?->matrimonyProfile?->id ?? 0) ?: null,
            'sender_name' => $name,
            'mail_action_url' => route('contact-inbox.index'),
            'mail_action_text' => __('notifications.open_contact_inbox'),
        ]);
    }
}
