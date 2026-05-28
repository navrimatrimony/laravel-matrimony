<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use App\Support\NotificationMarathiPayload;
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
        return $this->matrimonyMailFromPayload($this->toArray($notifiable), $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        $receiver = $this->mediationRequest->receiver;
        $name = $receiver->matrimonyProfile->full_name ?? $receiver->name ?? __('mediation.someone');
        $feedback = $this->mediationRequest->response_feedback;
        $receiverChoice = data_get($this->mediationRequest->meta, 'matchmaking.receiver_choice');

        $message = match ($receiverChoice) {
            'decide_later' => __('mediation.notify_sender_decide_later', ['name' => $name]),
            'talks_in_progress' => __('mediation.notify_sender_talks_in_progress', ['name' => $name]),
            default => match ($this->mediationRequest->status) {
                ContactRequest::STATUS_INTERESTED => __('mediation.notify_sender_interested', ['name' => $name]),
                ContactRequest::STATUS_NEED_MORE_INFO => __('mediation.notify_sender_need_more_info', [
                    'name' => $name,
                    'feedback_part' => $feedback
                        ? __('mediation.notify_sender_feedback_part', ['feedback' => $feedback])
                        : '',
                ]),
                default => __('mediation.notify_sender_not_interested', ['name' => $name]),
            },
        };

        if ($feedback && in_array($receiverChoice, ['decide_later', 'talks_in_progress'], true)) {
            $message .= __('mediation.notify_sender_feedback_part', ['feedback' => $feedback]);
        }

        return NotificationMarathiPayload::withMessage([
            'type' => 'mediation_request_response',
            'message' => $message,
            'contact_request_id' => $this->mediationRequest->id,
            'mediation_request_id' => $this->mediationRequest->id,
            'receiver_id' => $this->mediationRequest->receiver_id,
            'response' => $this->mediationRequest->status,
            'receiver_choice' => $receiverChoice,
            'feedback' => $feedback,
        ]);
    }
}
