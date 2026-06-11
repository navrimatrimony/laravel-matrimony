<?php

namespace App\Console\Commands;

use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakScheduledJobsConsolidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunSuchakScheduledJobs extends Command
{
    protected $signature = 'suchak:scheduled-jobs
        {--account-id= : Run scheduled jobs for one Suchak account id}
        {--admin-id= : Admin user id for admin-governed jobs}
        {--at= : Evaluation datetime, for example 2026-06-11 04:00:00}
        {--month= : Report/settlement month in YYYY-MM format; defaults to previous month}';

    protected $description = 'Run consolidated idempotent Suchak scheduled operations.';

    public function handle(SuchakScheduledJobsConsolidationService $service): int
    {
        $account = $this->account();
        $admin = $this->admin();
        $at = $this->option('at') ? Carbon::parse((string) $this->option('at')) : now();
        $month = $this->option('month') ? (string) $this->option('month') : null;

        $results = $service->run($admin, $account, $at, $month);

        $this->info('Suchak scheduled jobs completed: '.count($results));
        foreach ($results as $jobKey => $result) {
            $this->line($jobKey.': '.$result['job_status'].' (run #'.$result['run_id'].')');
        }

        return self::SUCCESS;
    }

    private function account(): ?SuchakAccount
    {
        $accountId = $this->option('account-id');
        if ($accountId === null || $accountId === '') {
            return null;
        }

        return SuchakAccount::query()->findOrFail((int) $accountId);
    }

    private function admin(): ?User
    {
        $adminId = $this->option('admin-id');
        if ($adminId !== null && $adminId !== '') {
            return User::query()->where('is_admin', true)->findOrFail((int) $adminId);
        }

        return User::query()
            ->where('is_admin', true)
            ->orderBy('id')
            ->first();
    }
}
