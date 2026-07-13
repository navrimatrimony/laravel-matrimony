<?php

namespace App\Console\Commands;

use App\Services\Intake\OcrEnsembleBenchmarkFieldExtractor;
use App\Services\Intake\OcrEnsembleBenchmarkScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OcrEnsembleBenchmarkScoreCommand extends Command
{
    protected $signature = 'ocr-ensemble:benchmark-score
        {batchId : Bulk intake batch id (e.g. 43 for Stage A ground truth)}
        {--engine=phase1_tesseract : Engine label for this report}
        {--stage=A : Benchmark stage (A or B)}
        {--predictions= : Optional JSON file with external engine field predictions keyed by intake_id}
        {--out= : Output JSON path (default: storage/app/private/ocr-ensemble-benchmark/)}';

    protected $description = 'Score OCR benchmark: approval_snapshot_json (truth) vs OCR text field extractor (not parsed_json).';

    public function handle(OcrEnsembleBenchmarkScorer $scorer): int
    {
        $batchId = (int) $this->argument('batchId');
        $engine = trim((string) $this->option('engine'));
        $stage = strtoupper(trim((string) $this->option('stage')));
        $predictionsPath = trim((string) ($this->option('predictions') ?? ''));

        if ($batchId < 1) {
            $this->error('batchId must be a positive integer.');

            return self::FAILURE;
        }

        $external = $predictionsPath !== '' ? $scorer->loadExternalPredictions($predictionsPath) : null;

        try {
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
        $this->info('OCR Ensemble benchmark report written.');
        $this->line('path='.$outPath);
        $this->line('comparison_table='.$tablePath);
        $this->line('images='.($summary['image_count'] ?? 0));
        $this->line('critical_accuracy='.($summary['critical_accuracy'] ?? 'n/a'));
        $this->line('avg_manual_correction_count='.($summary['avg_manual_correction_count'] ?? 'n/a'));
        $this->line('empty_ocr_rate='.($summary['empty_ocr_rate'] ?? 'n/a'));
        $this->line('failure_rate='.($summary['failure_rate'] ?? 'n/a'));

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
                .' corrections='.($item['manual_correction_count'] ?? 0));
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
