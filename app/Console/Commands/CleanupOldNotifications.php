<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| CleanupOldNotifications (SSOT Day-10 — Recovery-Day-R5)
|--------------------------------------------------------------------------
|
| Deletes notifications older than 90 days. Run daily via scheduler.
|
*/
class CleanupOldNotifications extends Command
{
    protected $signature = 'notifications:cleanup';

    protected $description = 'Delete notifications older than 90 days (Day-10 retention).';

    public function handle(): int
    {
        $since = now()->subDays(90);
        $deleted = DB::table('notifications')->where('created_at', '<', $since)->delete();
        $this->info("Deleted {$deleted} notification(s) older than 90 days.");
        return self::SUCCESS;
    }
}
