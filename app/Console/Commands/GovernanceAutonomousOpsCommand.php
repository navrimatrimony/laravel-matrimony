<?php

namespace App\Console\Commands;

use App\Jobs\RunGovernancePythonCommandJob;
use App\Services\DataEngineGovernanceService;
use Illuminate\Console\Command;

class GovernanceAutonomousOpsCommand extends Command
{
    protected $signature = 'governance:autonomous-ops {--queue : Dispatch tasks to isolated governance queues}';

    protected $description = 'Run autonomous governance scheduler orchestration workflows.';

    public function handle(DataEngineGovernanceService $governance): int
    {
        if (! config('data_engine.autonomous.enabled', true)) {
            $this->warn('Autonomous governance is disabled.');

            return self::SUCCESS;
        }

        $tasks = [
            ['parity-validate'],
            ['governance-regression'],
            ['snapshot-quarantine', '--retention-days', (string) config('data_engine.autonomous.quarantine_retention_days', 30)],
            ['governance-timeline'],
            ['relation-integrity'],
            ['ops-dashboard'],
        ];
        if ((bool) $this->option('queue')) {
            foreach ($tasks as $task) {
                $this->dispatchTask($task);
            }
            $this->info('Autonomous governance tasks dispatched to dedicated queues.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            $this->line('Running: '.implode(' ', $task));
            $governance->executePythonOpsCommand($task);
        }
        $this->info('Autonomous governance cycle completed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int,string>  $task
     */
    private function dispatchTask(array $task): void
    {
        $cmd = $task[0] ?? '';
        $queue = match ($cmd) {
            'snapshot-quarantine' => (string) config('data_engine.queues.quarantine', 'governance-quarantine'),
            'parity-validate', 'relation-integrity' => (string) config('data_engine.queues.snapshot', 'governance-snapshot'),
            'governance-regression', 'governance-timeline', 'ops-dashboard' => (string) config('data_engine.queues.comparison', 'governance-comparison'),
            default => (string) config('data_engine.queues.repair', 'governance-repair'),
        };
        RunGovernancePythonCommandJob::dispatch($task, $queue);
    }
}

