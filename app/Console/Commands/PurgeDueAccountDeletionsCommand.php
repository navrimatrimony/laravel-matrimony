<?php

namespace App\Console\Commands;

use App\Services\Account\MemberAccountDeletionService;
use Illuminate\Console\Command;

/**
 * Carries out account deletions whose 30-day grace period has expired.
 *
 * Scheduled daily in routes/console.php. Runs the same service the API calls,
 * so there is no second definition of what "due" means.
 */
class PurgeDueAccountDeletionsCommand extends Command
{
    protected $signature = 'account:purge-due-deletions
        {--days= : Override the grace period, for verifying the sweep without waiting 30 days}
        {--dry-run : List what would be erased and change nothing}';

    protected $description = 'Permanently erase member accounts past their deletion grace period.';

    public function handle(MemberAccountDeletionService $deletions): int
    {
        $days = $this->option('days') !== null
            ? max(0, (int) $this->option('days'))
            : MemberAccountDeletionService::GRACE_DAYS;

        if ($this->option('dry-run')) {
            $due = $deletions->dueForPurge($days);
            $this->info("Would purge {$due->count()} account(s) at a {$days}-day grace period.");
            foreach ($due as $user) {
                $this->line("  user #{$user->id} requested {$user->deletion_requested_at}");
            }

            return self::SUCCESS;
        }

        $result = $deletions->purgeDue($days);
        $this->info("purged={$result['purged']} failed={$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
