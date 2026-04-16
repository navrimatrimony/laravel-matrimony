<?php

namespace App\Console\Commands;

use App\Services\EngagementNotificationService;
use Illuminate\Console\Command;

class SendInactiveUserReminders extends Command
{
    protected $signature = 'engagement:inactive-reminders';

    protected $description = 'Remind members who have been away (database + email; optional WhatsApp template)';

    public function handle(EngagementNotificationService $engagement): int
    {
        $n = $engagement->sendInactiveReminders();
        $this->info("Sent {$n} inactive reminder(s).");

        return self::SUCCESS;
    }
}
