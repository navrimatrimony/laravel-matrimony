<?php

namespace App\Console\Commands;

use App\Services\DataEngineGovernanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GovernanceVerifySnapshotIntegrityCommand extends Command
{
    protected $signature = 'governance:verify-snapshot-integrity';

    protected $description = 'Verify snapshot extraction integrity and rendered path completeness.';

    public function handle(DataEngineGovernanceService $governance): int
    {
        $governance->executePythonOpsCommand(['compare', '--latest']);
        $governance->executePythonOpsCommand(['governance-runtime-truth']);
        $path = base_path('python-data-engine/output/comparisons');
        $files = glob($path.DIRECTORY_SEPARATOR.'snapshot_comparison_*.json') ?: [];
        rsort($files);
        if ($files === []) {
            $this->error('No comparison files found.');

            return self::FAILURE;
        }
        $cmp = json_decode((string) File::get($files[0]), true);
        $snapshotPath = (string) ($cmp['snapshot_path'] ?? '');
        if ($snapshotPath === '' || ! is_file($snapshotPath)) {
            $this->error('Latest snapshot not found.');

            return self::FAILURE;
        }
        $snap = json_decode((string) file_get_contents($snapshotPath), true);
        $renderedFields = is_array($snap['rendered']['fields'] ?? null) ? $snap['rendered']['fields'] : [];
        $repeaters = is_array($snap['repeaters'] ?? null) ? $snap['repeaters'] : [];
        $missingExpected = [];
        foreach (['full_name', 'date_of_birth'] as $expected) {
            if (! array_key_exists($expected, $renderedFields)) {
                $missingExpected[] = $expected;
            }
        }
        $truth = is_array($cmp['comparison_truth'] ?? null) ? $cmp['comparison_truth'] : [];
        $this->info('Snapshot integrity verification');
        $this->line('Total extracted fields: '.count($renderedFields));
        $this->line('Repeater count: '.count($repeaters));
        $this->line('Comparison truth compared_fields: '.count($truth['compared_fields'] ?? []));
        $this->line('Canonical registry meta present: '.(isset($truth['canonical_registry_meta']) ? 'yes' : 'no'));
        $this->line('Missing expected fields: '.count($missingExpected));
        if ($missingExpected !== []) {
            $this->warn('Missing: '.implode(', ', $missingExpected));
        }
        $unsupportedStructures = 0;
        foreach ($repeaters as $rows) {
            if (! is_array($rows)) {
                $unsupportedStructures++;
            }
        }
        $this->line('Unsupported structures: '.$unsupportedStructures);

        return self::SUCCESS;
    }
}

