<?php

namespace App\Console\Commands;

use App\Services\DataEngineGovernanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GovernanceDeploymentGateCommand extends Command
{
    protected $signature = 'governance:deployment-gate';

    protected $description = 'Run deployment governance gate and block on critical drift.';

    public function handle(DataEngineGovernanceService $governance): int
    {
        $regression = $governance->executePythonOpsCommand(['governance-regression']);
        $parity = $governance->executePythonOpsCommand(['parity-validate']);
        $integrity = $governance->executePythonOpsCommand(['relation-integrity']);
        $drift = $governance->executePythonOpsCommand(['api-drift-root-cause']);
        $threshold = (int) config('data_engine.autonomous.critical_drift_block_threshold', 1);
        $parityThreshold = (int) config('data_engine.autonomous.parity_block_threshold', 5);
        $orphanThreshold = (int) config('data_engine.autonomous.orphan_block_threshold', 1);

        $criticalDrift = (int) ($drift['api_drift_count'] ?? 0);
        $parityFailures = (int) (($parity['summary']['scalar_parity_failures'] ?? 0));
        $orphanRows = (int) ($integrity['total_orphan_rows'] ?? 0);
        $regressionOk = (bool) ($regression['all_passed'] ?? false);

        $blocked = $criticalDrift >= $threshold || $parityFailures >= $parityThreshold || $orphanRows >= $orphanThreshold || ! $regressionOk;
        $report = [
            'generated_at' => now()->toIso8601String(),
            'blocked' => $blocked,
            'critical_drift_count' => $criticalDrift,
            'parity_failures' => $parityFailures,
            'orphan_rows' => $orphanRows,
            'regression_passed' => $regressionOk,
            'thresholds' => [
                'critical_drift_block_threshold' => $threshold,
                'parity_block_threshold' => $parityThreshold,
                'orphan_block_threshold' => $orphanThreshold,
            ],
            'artifacts' => [
                'regression' => $regression,
                'parity' => $parity,
                'relation_integrity' => $integrity,
                'drift' => $drift,
            ],
        ];
        $path = base_path('python-data-engine/output/health/deployment_governance_report.json');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('Deployment governance report: '.$path);

        if ($blocked) {
            $this->error('Deployment governance gate failed.');

            return self::FAILURE;
        }
        $this->info('Deployment governance gate passed.');

        return self::SUCCESS;
    }
}

