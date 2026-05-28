<?php

namespace App\Jobs\Engagement;

use App\Services\EngagementNotificationService;
use App\Support\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunInactiveRemindersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $connection = NotificationQueue::engagementConnection();
        if ($connection !== null) {
            $this->onConnection($connection);
        }

        $this->onQueue(NotificationQueue::engagementQueueName());
    }

    public function handle(EngagementNotificationService $engagement): void
    {
        $sent = $engagement->sendInactiveReminders();

        Log::info('engagement_inactive_reminders_job_complete', ['sent' => $sent]);
    }
}
