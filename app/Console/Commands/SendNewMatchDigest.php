<?php

namespace App\Console\Commands;

use App\Services\EngagementNotificationService;
use Illuminate\Console\Command;

class SendNewMatchDigest extends Command
{
    protected $signature = 'engagement:new-match-digest';

    protected $description = 'Notify members when the matcher finds strong new candidates (database + email)';

    public function handle(EngagementNotificationService $engagement): int
    {
        $n = $engagement->sendNewMatchDigests();
        $this->info("Sent {$n} new-match digest notification(s).");

        return self::SUCCESS;
    }
}
