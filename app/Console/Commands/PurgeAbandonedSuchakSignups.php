<?php

namespace App\Console\Commands;

use App\Models\SuchakAccount;
use App\Modules\Suchak\Services\AbandonedSignupPurgeService;
use Illuminate\Console\Command;

/**
 * Terminal front-end for AbandonedSignupPurgeService. The admin "clean up"
 * button uses the same service, so both share one rule and one deletion path.
 *
 * Dry-run is the default; nothing is deleted without --force.
 */
class PurgeAbandonedSuchakSignups extends Command
{
    protected $signature = 'suchak:purge-abandoned-signups
                            {--days=30 : Minimum age in days (never below 30)}
                            {--force : Actually delete. Without this nothing is removed}';

    protected $description = 'Delete never-completed, never-used Suchak signups older than 30 days';

    public function handle(AbandonedSignupPurgeService $purger): int
    {
        $days = (int) $this->option('days');

        if ($days < AbandonedSignupPurgeService::MINIMUM_AGE_DAYS) {
            $this->error(sprintf(
                'Refusing to run: --days=%d is below the hard minimum of %d days.',
                $days,
                AbandonedSignupPurgeService::MINIMUM_AGE_DAYS
            ));

            return self::FAILURE;
        }

        $eligible = $purger->eligible($days);

        if ($eligible->isEmpty()) {
            $this->info(sprintf('Nothing to purge: no abandoned, unused signups older than %d days.', $days));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Mobile', 'Step', 'Created'],
            $eligible->map(fn (SuchakAccount $a): array => [
                $a->id,
                $a->suchak_name ?: '(no name)',
                $a->mobile_number ?: '—',
                $a->onboarding_step ?: '—',
                $a->created_at?->format('Y-m-d'),
            ])->all()
        );

        if (! $this->option('force')) {
            $this->warn(sprintf(
                'DRY RUN — nothing was deleted. %d account(s) would be removed. Re-run with --force to apply.',
                $eligible->count()
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('Deleted %d abandoned Suchak signup(s).', $purger->purge($days)));

        return self::SUCCESS;
    }
}
