<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MediationRequestResponseNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public ContactRequest $mediationRequest
    ) {}

    public function via(object $notifiable): array
    {
        return $this->matrimonyNotificationChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return MatrimonyMailTemplate::fromToArray($this->toArray($notifiable));
    }

    public function toArray(object $notifiable): array
    {
        $receiver = $this->mediationRequest->receiver;
        $name = $receiver->matrimonyProfile->full_name ?? $receiver->name ?? __('mediation.someone');
        $feedback = $this->mediationRequest->response_feedback;

        $message = match ($this->mediationRequest->status) {
            ContactRequest::STATUS_INTERESTED => __('mediation.notify_sender_interested', ['name' => $name]),
            ContactRequest::STATUS_NEED_MORE_INFO => __('mediation.notify_sender_need_more_info', [
                'name' => $name,
                'feedback_part' => $feedback
                    ? __('mediation.notify_sender_feedback_part', ['feedback' => $feedback])
                    : '',
            ]),
            default => __('mediation.notify_sender_not_interested', ['name' => $name]),
        };

        return [
            'type' => 'mediation_request_response',
            'message' => $message,
            'contact_request_id' => $this->mediationRequest->id,
            'mediation_request_id' => $this->mediationRequest->id,
            'receiver_id' => $this->mediationRequest->receiver_id,
            'response' => $this->mediationRequest->status,
            'feedback' => $feedback,
        ];
    }
}
