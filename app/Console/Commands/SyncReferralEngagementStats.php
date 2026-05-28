<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserEngagementStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncReferralEngagementStats extends Command
{
    protected $signature = 'engagement:sync-referrals {--user= : Sync a single referrer user id}';

    protected $description = 'Backfill referrals_done in user_engagement_stats from user_referrals';

    public function handle(UserEngagementStatsService $engagement): int
    {
        if (! Schema::hasTable('user_engagement_stats')) {
            $this->error('user_engagement_stats table is missing. Run migrations first.');

            return self::FAILURE;
        }

        $userId = $this->option('user');
        if ($userId !== null && $userId !== '') {
            $user = User::query()->find((int) $userId);
            if (! $user) {
                $this->error('User not found.');

                return self::FAILURE;
            }
            $engagement->syncReferralsDone($user);
            $this->info('Synced referrals_done for user #'.$user->id);

            return self::SUCCESS;
        }

        $referrerIds = User::query()
            ->whereIn('id', function ($q) {
                $q->select('referrer_id')->from('user_referrals')->distinct();
            })
            ->pluck('id');

        $bar = $this->output->createProgressBar($referrerIds->count());
        foreach ($referrerIds as $id) {
            $user = User::query()->find((int) $id);
            if ($user) {
                $engagement->syncReferralsDone($user);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info('Synced '.$referrerIds->count().' referrer(s).');

        return self::SUCCESS;
    }
}
