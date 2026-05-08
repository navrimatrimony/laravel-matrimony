<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class GovernanceQueueHealthCommand extends Command
{
    protected $signature = 'governance:queue-health {--recover-stuck : Release stuck governance jobs by making them available now}';

    protected $description = 'Report governance queue health, dead-letter risk, and stuck job recovery.';

    public function handle(): int
    {
        $queues = [
            (string) config('data_engine.queues.snapshot', 'governance-snapshot'),
            (string) config('data_engine.queues.comparison', 'governance-comparison'),
            (string) config('data_engine.queues.repair', 'governance-repair'),
            (string) config('data_engine.queues.quarantine', 'governance-quarantine'),
        ];
        $jobsByQueue = [];
        foreach ($queues as $q) {
            $jobsByQueue[$q] = DB::table('jobs')->where('queue', $q)->count();
        }
        $failedByQueue = [];
        foreach ($queues as $q) {
            $failedByQueue[$q] = DB::table('failed_jobs')->where('queue', $q)->count();
        }

        $stuck = DB::table('jobs')
            ->whereIn('queue', $queues)
            ->where('reserved_at', '>', 0)
            ->where('available_at', '<', now()->subMinutes(30)->timestamp)
            ->get(['id', 'queue', 'reserved_at', 'available_at']);

        if ((bool) $this->option('recover-stuck') && $stuck->count() > 0) {
            foreach ($stuck as $job) {
                DB::table('jobs')->where('id', $job->id)->update([
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                ]);
            }
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'queues' => $queues,
            'pending_jobs' => $jobsByQueue,
            'failed_jobs' => $failedByQueue,
            'stuck_jobs_count' => $stuck->count(),
            'recovered' => (bool) $this->option('recover-stuck'),
        ];
        $path = base_path('python-data-engine/output/health/governance_queue_health.json');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Governance queue health report written.');

        return self::SUCCESS;
    }
}

