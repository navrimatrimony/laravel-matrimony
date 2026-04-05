<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MediationRequestReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ContactRequest $mediationRequest
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $sender = $this->mediationRequest->sender;
        $name = $sender->matrimonyProfile->full_name ?? $sender->name ?? __('mediation.someone');
        $hint = (string) data_get($this->mediationRequest->meta, 'matchmaking.compatibility_hint', '');
        $message = $hint !== ''
            ? __('mediation.notify_receiver_assisted', ['name' => $name, 'hint' => $hint])
            : __('mediation.notify_receiver_body', ['name' => $name]);

        return [
            'type' => 'mediation_request_received',
            'message' => $message,
            'contact_request_id' => $this->mediationRequest->id,
            'mediation_request_id' => $this->mediationRequest->id,
            'sender_id' => $this->mediationRequest->sender_id,
            'sender_profile_id' => $this->mediationRequest->sender_profile_id,
            'receiver_profile_id' => $this->mediationRequest->receiver_profile_id,
            'subject_profile_id' => $this->mediationRequest->subject_profile_id,
            'compatibility_hint' => $hint,
        ];
    }
}
