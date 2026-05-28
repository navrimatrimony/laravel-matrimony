<?php

namespace App\Console\Commands;

use App\Jobs\Engagement\RunInactiveRemindersJob;
use App\Services\EngagementNotificationService;
use App\Support\NotificationQueue;
use Illuminate\Console\Command;

class SendInactiveUserReminders extends Command
{
    protected $signature = 'engagement:inactive-reminders';

    protected $description = 'Remind members who have been away (database + email; optional WhatsApp template)';

    public function handle(EngagementNotificationService $engagement): int
    {
        if (NotificationQueue::engagementBatchesEnabled()) {
            RunInactiveRemindersJob::dispatch();
            $this->info('Inactive reminders queued ('.NotificationQueue::engagementQueueName().').');

            return self::SUCCESS;
        }

        $n = $engagement->sendInactiveReminders();
        $this->info("Sent {$n} inactive reminder(s).");

        return self::SUCCESS;
    }
}
