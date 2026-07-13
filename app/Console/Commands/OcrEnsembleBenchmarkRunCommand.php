<?php

namespace App\Console\Commands;

use App\Services\Intake\OcrEnsembleBenchmarkBatchOcrRunner;
use App\Services\Intake\OcrEnsembleBenchmarkEasyOcrClient;
use App\Services\Intake\OcrEnsembleBenchmarkFieldExtractor;
use App\Services\Intake\OcrEnsembleBenchmarkPaddleOcrClient;
use App\Services\Intake\OcrEnsembleBenchmarkScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OcrEnsembleBenchmarkRunCommand extends Command
{
    protected $signature = 'ocr-ensemble:benchmark-run
        {batchId : Bulk intake batch id (e.g. 43)}
        {--engine=easyocr_v1 : Candidate OCR engine label (easyocr_v1 or paddleocr_v1)}
        {--stage=B : Benchmark stage label}
        {--baseline=68.75 : Phase 1 baseline critical accuracy percent for comparison}
        {--predictions= : Reuse an existing predictions JSON instead of running OCR}
        {--out= : Output JSON path for scored report}';

    protected $description = 'Run candidate OCR on a benchmark batch, score with frozen extractor, and compare to baseline.';

    public function handle(
        OcrEnsembleBenchmarkBatchOcrRunner $batchRunner,
        OcrEnsembleBenchmarkScorer $scorer,
    ): int {
        $batchId = (int) $this->argument('batchId');
        $engine = trim((string) $this->option('engine'));
        $stage = strtoupper(trim((string) $this->option('stage')));
        $baselinePercent = (float) $this->option('baseline');
        $predictionsPath = trim((string) ($this->option('predictions') ?? ''));

        if ($batchId < 1) {
            $this->error('batchId must be a positive integer.');

            return self::FAILURE;
        }

        $supportedEngines = [
            OcrEnsembleBenchmarkEasyOcrClient::ENGINE,
            OcrEnsembleBenchmarkPaddleOcrClient::ENGINE,
        ];

        if (! in_array($engine, $supportedEngines, true)) {
            $this->error('Supported engines: '.implode(', ', $supportedEngines));

            return self::FAILURE;
        }

        try {
            if ($predictionsPath === '') {
                $this->info('Running '.$engine.' on batch #'.$batchId.' images...');
                $payload = $batchRunner->runBatch($batchId, $engine);
                $predictionsPath = $batchRunner->savePredictions($payload, $batchId, $engine);
                $this->line('predictions='.$predictionsPath);
            } else {
                $this->line('Using existing predictions: '.$predictionsPath);
            }

            $external = $scorer->loadExternalPredictions($predictionsPath);
            $report = $scorer->scoreBatch($batchId, $engine, $stage, $external);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $outPath = trim((string) ($this->option('out') ?? ''));
        if ($outPath === '') {
            $dir = storage_path('app/private/ocr-ensemble-benchmark');
            File::ensureDirectoryExists($dir);
            $slug = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($engine)) ?? 'engine';
            $outPath = $dir.DIRECTORY_SEPARATOR."stage_{$stage}_{$slug}_batch{$batchId}_".date('Ymd_His').'.json';
        } else {
            File::ensureDirectoryExists(dirname($outPath));
        }

        file_put_contents(
            $outPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $tablePath = preg_replace('/\.json$/i', '.comparison.csv', $outPath) ?? ($outPath.'.comparison.csv');
        file_put_contents($tablePath, $this->buildComparisonCsv($report, $engine));

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $criticalPercent = isset($summary['critical_accuracy'])
            ? round(((float) $summary['critical_accuracy']) * 100, 2)
            : null;
        $delta = $criticalPercent === null ? null : round($criticalPercent - $baselinePercent, 2);

        $this->newLine();
        $this->info('OCR Ensemble benchmark run complete.');
        $this->line('report='.$outPath);
        $this->line('comparison_table='.$tablePath);
        $this->line('predictions='.$predictionsPath);
        $this->line('engine='.$engine);
        $this->line('stage='.$stage);
        $this->line('images='.($summary['image_count'] ?? 0));
        $this->line('critical_accuracy='.($criticalPercent ?? 'n/a').'%');
        $this->line('phase1_baseline='.$baselinePercent.'%');
        $this->line('delta_vs_baseline='.($delta === null ? 'n/a' : $delta.'pp'));
        $this->line('go_threshold=+5.00pp');

        if ($delta !== null) {
            $this->line('go_no_go='.($delta >= 5 ? 'PROMISING (meets +5pp on this batch)' : 'NO-GO on +5pp rule for this batch'));
        }

        $fieldAccuracy = is_array($summary['field_accuracy'] ?? null) ? $summary['field_accuracy'] : [];
        $this->newLine();
        $this->table(
            ['Field', 'Accuracy'],
            collect($fieldAccuracy)->map(fn ($acc, $field) => [
                $field,
                $acc === null ? 'n/a' : ((string) round((float) $acc * 100, 1)).'%',
            ])->values()->all()
        );

        foreach ($report['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $this->newLine();
            $this->line('Item #'.($item['batch_item_id'] ?? '?').' intake #'.($item['intake_id'] ?? '?')
                .' corrections='.($item['manual_correction_count'] ?? 0)
                .' ocr_time_ms='.($item['ocr_time_ms'] ?? 'n/a'));
            $rows = [];
            foreach (OcrEnsembleBenchmarkFieldExtractor::CRITICAL_FIELDS as $field) {
                $row = is_array($item['fields'][$field] ?? null) ? $item['fields'][$field] : [];
                $rows[] = [
                    $field,
                    ($row['match'] ?? false) ? '✔' : '✘',
                    mb_substr((string) ($row['truth'] ?? ''), 0, 40),
                    mb_substr((string) ($row['prediction'] ?? ''), 0, 40),
                ];
            }
            $this->table(['Field', 'Match', 'Truth', 'Prediction'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function buildComparisonCsv(array $report, string $engineLabel): string
    {
        $rows = ['intake_id,field,truth,prediction,match,engine'];
        foreach ($report['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $intakeId = (string) ($item['intake_id'] ?? '');
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            foreach (OcrEnsembleBenchmarkFieldExtractor::ALL_FIELDS as $field) {
                $row = is_array($fields[$field] ?? null) ? $fields[$field] : [];
                $truth = (string) ($row['truth'] ?? '');
                if ($truth === '') {
                    continue;
                }
                $prediction = (string) ($row['prediction'] ?? '');
                $match = ($row['match'] ?? false) ? 'yes' : 'no';
                $rows[] = implode(',', [
                    $this->csvCell($intakeId),
                    $this->csvCell($field),
                    $this->csvCell($truth),
                    $this->csvCell($prediction),
                    $this->csvCell($match),
                    $this->csvCell($engineLabel),
                ]);
            }
        }

        return implode("\n", $rows)."\n";
    }

    private function csvCell(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (str_contains($value, ',') || str_contains($value, '"')) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
