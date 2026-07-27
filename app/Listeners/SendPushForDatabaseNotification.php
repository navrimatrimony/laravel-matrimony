<?php

namespace App\Listeners;

use App\Services\Push\PushDispatchService;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * The one and only bridge between "a notification row was written" and "send a
 * push". Registered in AppServiceProvider.
 *
 * Why an event listener rather than a call inside each business flow: there are
 * ~23 notification types today and more coming, and the product owner must be
 * able to switch any of them on or off at runtime. Wiring push at each business
 * event would scatter that decision across the codebase and guarantee that some
 * future notification silently forgets to push. Listening to the database channel
 * means every notification — including ones nobody has written yet — arrives at
 * the same switchboard.
 *
 * Only the `database` channel is handled. Mail sends fire this event too, and a
 * member who gets both an email and an in-app row must not get two pushes.
 */
class SendPushForDatabaseNotification
{
    public function __construct(private readonly PushDispatchService $dispatcher) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $stored = $event->response;

        // `notifications.type` is what the admin switchboard is keyed on. Laravel
        // lets a notification override it via databaseType(), so prefer the value
        // actually persisted and fall back to the class name.
        $notificationType = is_object($stored) && isset($stored->type)
            ? (string) $stored->type
            : $event->notification::class;

        $data = [];
        if (is_object($stored) && isset($stored->data)) {
            $data = is_array($stored->data) ? $stored->data : (array) json_decode((string) $stored->data, true);
        }

        // The notification id is what a tap should mark as read.
        if (is_object($stored) && isset($stored->id) && ! isset($data['id'])) {
            $data['id'] = (string) $stored->id;
        }

        $this->dispatcher->dispatchForDatabaseNotification($event->notifiable, $notificationType, $data);
    }
}
