<?php

namespace App\Console\Commands;

use App\Services\DataAudit\OperationsService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DataAuditCleanupCommand extends Command
{
    protected $signature = 'data-audit:cleanup
        {--dry-run : Show what would be pruned}
        {--execute : Apply retention cleanup}
        {--snapshot-max-per-profile= : Override snapshot per-profile limit}
        {--comparison-max-files= : Override comparison files limit}';

    protected $description = 'Run snapshot/comparison retention cleanup';

    public function handle(OperationsService $ops): int
    {
        $execute = (bool) $this->option('execute');
        $result = $ops->runLockedOperation('cleanup', function () use ($ops, $execute) {
            $runner = (string) config('data_engine.runner_path', base_path('python-data-engine/scripts/runner.py'));
            $args = [$runner, 'compare-cleanup'];
            $args[] = $execute ? '--execute' : '--dry-run';
            if ($this->option('snapshot-max-per-profile')) {
                $args[] = '--snapshot-max-per-profile='.(int) $this->option('snapshot-max-per-profile');
            }
            if ($this->option('comparison-max-files')) {
                $args[] = '--comparison-max-files='.(int) $this->option('comparison-max-files');
            }

            $process = $this->runWithFallbackPython($args);
            if (! $process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Cleanup command failed.');
            }

            return [
                'python_cleanup_stdout' => trim($process->getOutput()),
                'retention' => $ops->applyRetention($execute),
            ];
        }, 900);

        if ($result['status'] === 'skipped_locked') {
            $this->warn('Cleanup skipped: overlapping run prevented.');
            return self::SUCCESS;
        }
        if (! $result['ok']) {
            $this->error((string) ($result['error'] ?? 'Cleanup command failed.'));
            return self::FAILURE;
        }

        $ctx = $result['context'];
        $this->line((string) ($ctx['python_cleanup_stdout'] ?? ''));
        $this->info(json_encode($ctx['retention'] ?? [], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $runnerArgs
     */
    private function runWithFallbackPython(array $runnerArgs): Process
    {
        $binaries = array_values(array_unique(array_filter([
            (string) config('data_engine.python_binary', ''),
            'python',
        ])));

        $last = null;
        foreach ($binaries as $bin) {
            $process = new Process(array_merge([$bin], $runnerArgs), base_path());
            $process->setTimeout(300);
            $process->run();
            $last = $process;
            if ($process->isSuccessful()) {
                return $process;
            }
            $err = $process->getErrorOutput();
            if (! str_contains($err, 'ModuleNotFoundError')) {
                return $process;
            }
        }

        return $last ?? new Process(['python', '-V'], base_path());
    }
}

