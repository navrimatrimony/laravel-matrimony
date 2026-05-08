<?php

namespace App\Console\Commands;

use App\Services\DataAudit\OperationsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class DataAuditCompareCommand extends Command
{
    protected $signature = 'data-audit:compare
        {--latest : Compare latest snapshot}
        {--profile= : Compare by profile/entity id}';

    protected $description = 'Run deterministic snapshot comparison engine';

    public function handle(OperationsService $ops): int
    {
        $result = $ops->runLockedOperation('compare', function () use ($ops) {
            $runner = (string) config('data_engine.runner_path', base_path('python-data-engine/scripts/runner.py'));
            $args = [$runner, 'compare'];
            // Always compare against the latest snapshot for deterministic admin workflows.
            $args[] = '--latest';
            if ($this->option('profile')) {
                $args[] = '--profile='.(int) $this->option('profile');
            }

            $process = $this->runWithFallbackPython($args);
            $stdout = trim($process->getOutput());
            if (! $process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Comparison command failed.');
            }

            $comparisonDir = (string) config('data-governance.platform.storage.comparison_base_path', base_path('python-data-engine/output/comparisons'));
            $latestFile = null;
            if (is_dir($comparisonDir)) {
                $files = File::files($comparisonDir);
                usort($files, fn ($a, $b) => $b->getMTime() <=> $a->getMTime());
                $latestFile = $files[0] ?? null;
            }

            if ($latestFile !== null) {
                $decoded = json_decode((string) file_get_contents($latestFile->getPathname()), true);
                if (! is_array($decoded)) {
                    $ops->quarantineFile($latestFile->getPathname(), 'invalid comparison JSON');
                }
            }

            return ['stdout' => $stdout, 'latest_file' => $latestFile?->getPathname()];
        }, 900);

        if ($result['status'] === 'skipped_locked') {
            $this->warn('Compare skipped: overlapping run prevented.');
            return self::SUCCESS;
        }
        if (! $result['ok']) {
            $this->error((string) ($result['error'] ?? 'Comparison command failed.'));
            return self::FAILURE;
        }

        $stdout = (string) ($result['context']['stdout'] ?? '');
        if ($stdout !== '') {
            $this->line($stdout);
        }

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

