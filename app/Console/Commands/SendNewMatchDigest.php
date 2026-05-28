<?php

namespace App\Console\Commands;

use App\Jobs\Engagement\RunNewMatchDigestJob;
use App\Services\EngagementNotificationService;
use App\Support\NotificationQueue;
use Illuminate\Console\Command;

class SendNewMatchDigest extends Command
{
    protected $signature = 'engagement:new-match-digest';

    protected $description = 'Notify members when the matcher finds strong new candidates (database + email)';

    public function handle(EngagementNotificationService $engagement): int
    {
        if (NotificationQueue::engagementBatchesEnabled()) {
            RunNewMatchDigestJob::dispatch();
            $this->info('New-match digest queued ('.NotificationQueue::engagementQueueName().').');

            return self::SUCCESS;
        }

        $n = $engagement->sendNewMatchDigests();
        $this->info("Sent {$n} new-match digest notification(s).");

        return self::SUCCESS;
    }
}
