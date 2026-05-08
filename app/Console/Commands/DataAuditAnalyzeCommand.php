<?php

namespace App\Console\Commands;

use App\Services\DataAudit\OperationsService;
use App\Services\DataEngineService;
use Illuminate\Console\Command;

class DataAuditAnalyzeCommand extends Command
{
    protected $signature = 'data-audit:analyze';

    protected $description = 'Run deterministic analyze with ops heartbeat and locking';

    public function handle(DataEngineService $engine, OperationsService $ops): int
    {
        if (! $engine->isEffectiveEnabled()) {
            $this->warn('Analyze skipped: engine disabled.');
            return self::SUCCESS;
        }

        $result = $ops->runLockedOperation('analyze', function () use ($engine) {
            $run = $engine->run('analyze');

            return [
                'run_id' => $run->id,
                'status' => $run->status,
                'quality_score' => $run->quality_score,
                'total_issues' => $run->total_issues,
            ];
        }, (int) config('data_engine.timeout_seconds', 300) + 180);

        if ($result['status'] === 'skipped_locked') {
            $this->warn('Analyze skipped: overlapping run prevented.');
            return self::SUCCESS;
        }
        if (! $result['ok']) {
            $this->error((string) ($result['error'] ?? 'Analyze failed.'));
            return self::FAILURE;
        }

        $this->info(json_encode($result['context'], JSON_UNESCAPED_SLASHES));

        return (($result['context']['status'] ?? '') === 'failed') ? self::FAILURE : self::SUCCESS;
    }
}

