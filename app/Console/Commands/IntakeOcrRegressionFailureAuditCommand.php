<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

class IntakeOcrRegressionFailureAuditCommand extends Command
{
    private const FIELDS = [
        'full_name',
        'date_of_birth',
        'height',
        'education',
        'occupation',
        'primary_contact_number',
        'document_contact_number',
        'address',
        'religion',
        'caste',
        'sub_caste',
    ];

    protected $signature = 'intake:ocr-regression-failure-audit
        {--dataset= : Dataset JSONL/JSON file path under storage/app or tests/Fixtures}
        {--field= : Optional field filter}
        {--json : Print JSON}
        {--limit=500 : Maximum mismatch row details to print}';

    protected $description = 'Read-only safe inventory of remaining offline OCR regression failures.';

    public function handle(): int
    {
        $dataset = trim((string) $this->option('dataset'));
        $field = $this->fieldOption();
        $detailLimit = max(0, min(5000, (int) $this->option('limit')));

        if ($dataset === '') {
            $this->error('Dataset file is required.');

            return self::FAILURE;
        }

        if ($field === false) {
            return self::FAILURE;
        }

        $regression = $this->runRegression($dataset, $field);
        if ($regression === null) {
            return self::FAILURE;
        }

        $report = $this->buildAuditReport($regression, $field, $detailLimit);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderAuditReport($report);
        }

        return self::SUCCESS;
    }

    private function fieldOption(): string|false|null
    {
        $value = Str::of((string) $this->option('field'))->trim()->lower()->toString();
        if ($value === '') {
            return null;
        }

        if (! in_array($value, self::FIELDS, true)) {
            $this->error('Invalid --field value. Allowed: '.implode(', ', self::FIELDS));

            return false;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runRegression(string $dataset, ?string $field): ?array
    {
        $parameters = [
            '--dataset' => $dataset,
            '--json' => true,
            '--limit' => 5000,
        ];
        if ($field !== null) {
            $parameters['--field'] = $field;
        }

        $output = new BufferedOutput;
        $exitCode = Artisan::call('intake:ocr-regression', $parameters, $output);
        $payload = json_decode(trim($output->fetch()), true);

        if (! is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Regression command did not return valid JSON.');

            return null;
        }

        if ($exitCode !== self::SUCCESS && ($payload['summary']['regression_status'] ?? null) !== 'pass') {
            $this->error('Regression dataset could not be evaluated safely.');

            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $regression
     * @return array<string, mixed>
     */
    private function buildAuditReport(array $regression, ?string $field, int $detailLimit): array
    {
        $summary = is_array($regression['summary'] ?? null) ? $regression['summary'] : [];
        $datasetRows = (int) ($summary['total_cases'] ?? 0);
        $validRows = (int) ($summary['valid_cases'] ?? $datasetRows);
        $fieldRows = $this->fieldSummaryRows(
            is_array($regression['field_accuracy'] ?? null) ? $regression['field_accuracy'] : [],
            $validRows
        );
        $detailRows = $this->detailRows(
            is_array($regression['rows'] ?? null) ? $regression['rows'] : [],
            $detailLimit
        );

        return [
            'dataset_rows' => $datasetRows,
            'field_filter' => $field,
            'detail_limit' => $detailLimit,
            'evaluated_fields' => array_values(array_map(
                fn (array $row): string => (string) $row['field'],
                $fieldRows
            )),
            'fields' => $fieldRows,
            'rows' => $detailRows,
            'layout_status_summary' => $this->layoutStatusSummary(
                is_array($regression['rows'] ?? null) ? $regression['rows'] : []
            ),
            'recommendation' => $this->recommendation($fieldRows),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fieldAccuracyRows
     * @return list<array<string, mixed>>
     */
    private function fieldSummaryRows(array $fieldAccuracyRows, int $validRows): array
    {
        return array_values(array_map(function (array $row) use ($validRows): array {
            $expected = (int) ($row['total_expected'] ?? 0);

            return [
                'field' => (string) ($row['field'] ?? 'unknown'),
                'matched' => (int) ($row['exact_match_count'] ?? 0),
                'mismatched' => (int) ($row['mismatch_count'] ?? 0),
                'missing_expected' => max(0, $validRows - $expected),
                'missing_actual' => (int) ($row['missing_count'] ?? 0),
                'accuracy_percent' => (float) ($row['accuracy_percent'] ?? 0),
            ];
        }, $fieldAccuracyRows));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function detailRows(array $rows, int $detailLimit): array
    {
        $details = [];
        foreach ($rows as $index => $row) {
            $mismatchFields = $this->safeFieldList($row['mismatch_fields'] ?? []);
            $missingFields = $this->safeFieldList($row['missing_fields'] ?? []);
            if ($mismatchFields === [] && $missingFields === []) {
                continue;
            }

            $details[] = [
                'row_label' => sprintf('case_%03d', $index + 1),
                'layout' => $this->safeToken((string) ($row['layout_type'] ?? ''), 'unknown'),
                'status' => $this->safeToken((string) ($row['status'] ?? ''), 'unknown'),
                'mismatch_fields' => $mismatchFields,
                'missing_fields' => $missingFields,
            ];

            if (count($details) >= $detailLimit) {
                break;
            }
        }

        return $details;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function layoutStatusSummary(array $rows): array
    {
        $summary = [];
        foreach ($rows as $row) {
            $layout = $this->safeToken((string) ($row['layout_type'] ?? ''), 'unknown');
            $status = $this->safeToken((string) ($row['status'] ?? ''), 'unknown');
            $key = $layout.'|'.$status;

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'layout' => $layout,
                    'status' => $status,
                    'rows' => 0,
                ];
            }

            $summary[$key]['rows']++;
        }

        return array_values($summary);
    }

    /**
     * @param  list<array<string, mixed>>  $fieldRows
     * @return array<string, mixed>
     */
    private function recommendation(array $fieldRows): array
    {
        $candidate = null;
        foreach ($fieldRows as $row) {
            $failures = (int) $row['mismatched'] + (int) $row['missing_actual'];
            if ($failures <= 0) {
                continue;
            }

            if (
                $candidate === null
                || $failures > $candidate['remaining_failure_count']
                || (
                    $failures === $candidate['remaining_failure_count']
                    && (float) $row['accuracy_percent'] < (float) $candidate['accuracy_percent']
                )
            ) {
                $candidate = [
                    'field' => (string) $row['field'],
                    'remaining_failure_count' => $failures,
                    'accuracy_percent' => (float) $row['accuracy_percent'],
                ];
            }
        }

        if ($candidate === null) {
            return [
                'next_candidate_field' => null,
                'reason' => 'no_remaining_failures',
                'remaining_failure_count' => 0,
            ];
        }

        return [
            'next_candidate_field' => $candidate['field'],
            'reason' => 'highest remaining safe failure count',
            'remaining_failure_count' => $candidate['remaining_failure_count'],
            'accuracy_percent' => $candidate['accuracy_percent'],
        ];
    }

    /**
     * @return list<string>
     */
    private function safeFieldList(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $field): ?string {
            $field = $this->safeToken((string) $field, '');

            return in_array($field, self::FIELDS, true) ? $field : null;
        }, $fields)));
    }

    private function safeToken(string $value, string $fallback): string
    {
        $value = Str::of($value)->trim()->lower()->replaceMatches('/[^a-z0-9_\-]+/', '_')->trim('_')->toString();

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderAuditReport(array $report): void
    {
        $this->info('Intake OCR Regression Failure Audit');
        $this->line('dataset_rows: '.$report['dataset_rows']);
        $this->line('evaluated_fields: '.implode(', ', $report['evaluated_fields']));
        $this->line('field_filter: '.($report['field_filter'] ?? 'all'));
        $this->line('detail_limit: '.$report['detail_limit']);

        $this->newLine();
        $this->line('Field summary');
        $this->table(
            ['Field', 'Matched', 'Mismatched', 'Missing expected', 'Missing actual', 'Accuracy %'],
            array_map(fn (array $row): array => [
                $row['field'],
                $row['matched'],
                $row['mismatched'],
                $row['missing_expected'],
                $row['missing_actual'],
                $row['accuracy_percent'],
            ], $report['fields'])
        );

        $this->line('Row mismatch inventory');
        $this->table(
            ['Row label', 'Layout', 'Status', 'Mismatch fields', 'Missing fields'],
            array_map(fn (array $row): array => [
                $row['row_label'],
                $row['layout'],
                $row['status'],
                implode(', ', $row['mismatch_fields']),
                implode(', ', $row['missing_fields']),
            ], $report['rows'])
        );

        $this->line('Layout/status summary');
        $this->table(
            ['Layout', 'Status', 'Rows'],
            array_map(fn (array $row): array => [
                $row['layout'],
                $row['status'],
                $row['rows'],
            ], $report['layout_status_summary'])
        );

        $recommendation = $report['recommendation'];
        $this->line('recommendation: next_candidate_field = '.($recommendation['next_candidate_field'] ?? 'none'));
        $this->line('recommendation_reason: '.$recommendation['reason']);
    }
}
