<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class IntakeGoldenDatasetCsvToJsonlCommand extends Command
{
    private const OUTPUT_DIR = 'storage/app/intake-golden-datasets';

    private const COLUMNS = [
        'case_id',
        'layout_type',
        'language',
        'ocr_text',
        'parser_full_name',
        'parser_date_of_birth',
        'parser_height',
        'parser_education',
        'parser_occupation',
        'parser_document_contact_number',
        'parser_document_address',
        'parser_religion',
        'parser_caste',
        'parser_sub_caste',
        'profile_full_name',
        'profile_date_of_birth',
        'profile_birth_time',
        'profile_birth_place',
        'profile_height_text',
        'profile_education',
        'profile_occupation',
        'profile_religion',
        'profile_caste',
        'profile_sub_caste',
        'profile_primary_contact_number',
        'profile_document_contacts',
        'current_address_raw',
        'current_village_or_city',
        'current_taluka',
        'current_district',
        'current_state',
        'native_address_raw',
        'native_village_or_city',
        'native_taluka',
        'native_district',
        'native_state',
        'birth_place_raw',
        'father_name',
        'mother_name',
        'brother_notes',
        'sister_notes',
        'family_notes',
        'relatives_notes',
        'property_notes',
        'expectations_notes',
        'source_primary_contact_rule',
        'source_consent_note',
        'curator_notes',
    ];

    private const PARSER_FIELD_COLUMNS = [
        'parser_full_name' => 'full_name',
        'parser_date_of_birth' => 'date_of_birth',
        'parser_height' => 'height',
        'parser_education' => 'education',
        'parser_occupation' => 'occupation',
        'parser_document_contact_number' => 'document_contact_number',
        'parser_document_address' => 'address',
        'parser_religion' => 'religion',
        'parser_caste' => 'caste',
        'parser_sub_caste' => 'sub_caste',
    ];

    protected $signature = 'intake:golden-dataset-csv-to-jsonl
        {--csv=storage/app/intake-golden-datasets/golden-curation-template.csv : Input CSV path under storage/app/intake-golden-datasets}
        {--output=storage/app/intake-golden-datasets/golden.jsonl : Output JSONL path under storage/app/intake-golden-datasets}
        {--force : Overwrite if output exists}
        {--json : Print JSON}';

    protected $description = 'Convert a private golden dataset CSV curation file into JSONL for offline OCR regression.';

    public function handle(): int
    {
        $csvOption = $this->csvOption();
        $outputOption = $this->outputOption();
        $csvPath = $this->resolvePrivatePath($csvOption);
        $outputPath = $this->resolvePrivatePath($outputOption);

        if ($csvPath === false) {
            return $this->failReport(
                output: $outputOption,
                code: 'csv_path_not_allowed',
                message: 'CSV path is not allowed. Use storage/app/intake-golden-datasets/... only.'
            );
        }

        if ($outputPath === false) {
            return $this->failReport(
                output: $outputOption,
                code: 'output_path_not_allowed',
                message: 'Output path is not allowed. Use storage/app/intake-golden-datasets/... only.'
            );
        }

        if (strtolower(str_replace('\\', '/', $csvPath)) === strtolower(str_replace('\\', '/', $outputPath))) {
            return $this->failReport(
                output: $this->safeOutputDisplay($outputPath),
                code: 'csv_output_same_path',
                message: 'CSV input and JSONL output must be different files.'
            );
        }

        if (! File::exists($csvPath)) {
            return $this->failReport(
                output: $this->safeOutputDisplay($outputPath),
                code: 'csv_file_missing',
                message: 'CSV file was not found.'
            );
        }

        if (File::exists($outputPath) && ! (bool) $this->option('force')) {
            return $this->failReport(
                output: $this->safeOutputDisplay($outputPath),
                code: 'output_file_exists',
                message: 'Output file already exists. Re-run with --force to overwrite it.'
            );
        }

        $loaded = $this->loadCsv($csvPath);
        if ($loaded['validation_errors'] !== []) {
            return $this->validationFailureReport($this->safeOutputDisplay($outputPath), $loaded);
        }

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $this->jsonl($loaded['cases']));

        $report = [
            'success' => true,
            'output_path' => $this->safeOutputDisplay($outputPath),
            'summary' => $this->summary($loaded),
            'validation_errors' => [],
            'regression_command' => 'php artisan intake:ocr-regression --dataset='.$this->safeOutputDisplay($outputPath),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Private golden dataset JSONL written.');
        $this->line('Output: '.$report['output_path']);
        $this->line('CSV rows: '.$report['summary']['csv_rows']);
        $this->line('Cases written: '.$report['summary']['cases_written']);
        $this->line('Skipped blank rows: '.$report['summary']['skipped_blank_rows']);
        $this->line('Profile snapshots: '.$report['summary']['profile_snapshot_count']);
        $this->line('Source contexts: '.$report['summary']['source_context_count']);
        $this->line('Run regression: '.$report['regression_command']);

        return self::SUCCESS;
    }

    private function csvOption(): string
    {
        $value = trim((string) $this->option('csv'));

        return $value === '' ? self::OUTPUT_DIR.'/golden-curation-template.csv' : $value;
    }

    private function outputOption(): string
    {
        $value = trim((string) $this->option('output'));

        return $value === '' ? self::OUTPUT_DIR.'/golden.jsonl' : $value;
    }

    private function failReport(string $output, string $code, string $message): int
    {
        $report = [
            'success' => false,
            'output_path' => $output,
            'summary' => $this->emptySummary(),
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'validation_errors' => [],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);
        $this->line('Allowed CSV example: php artisan intake:golden-dataset-csv-to-jsonl --csv=storage/app/intake-golden-datasets/golden-curation-template.csv');

        return self::FAILURE;
    }

    /**
     * @param  array{cases: list<array<string, mixed>>, validation_errors: list<array<string, mixed>>, csv_rows: int, skipped_blank_rows: int, fields_present_counts: array<string, int>, profile_snapshot_count: int, source_context_count: int}  $loaded
     */
    private function validationFailureReport(string $output, array $loaded): int
    {
        $report = [
            'success' => false,
            'output_path' => $output,
            'summary' => $this->summary($loaded, written: false),
            'error' => [
                'code' => 'csv_validation_failed',
                'message' => 'CSV rows failed validation. Fix all invalid rows before converting.',
            ],
            'validation_errors' => $loaded['validation_errors'],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error('CSV rows failed validation. Fix all invalid rows before converting.');
        $this->table(
            ['Row', 'Error codes'],
            array_map(
                fn (array $error): array => [
                    $error['row_number'],
                    implode(', ', $error['error_codes']),
                ],
                $loaded['validation_errors']
            )
        );

        return self::FAILURE;
    }

    private function resolvePrivatePath(string $path): string|false
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || str_contains($normalized, "\0") || preg_match('~(^|/)\.\.(/|$)~', $normalized)) {
            return false;
        }

        $allowedDir = str_replace('\\', '/', storage_path('app/intake-golden-datasets'));
        if ($this->isAbsolutePath($normalized)) {
            $candidate = rtrim($normalized, '/');

            return str_starts_with(strtolower($candidate), strtolower($allowedDir).'/')
                ? $candidate
                : false;
        }

        if (! str_starts_with($normalized, self::OUTPUT_DIR.'/')) {
            return false;
        }

        return base_path($normalized);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    /**
     * @return array{cases: list<array<string, mixed>>, validation_errors: list<array<string, mixed>>, csv_rows: int, skipped_blank_rows: int, fields_present_counts: array<string, int>, profile_snapshot_count: int, source_context_count: int}
     */
    private function loadCsv(string $path): array
    {
        $result = [
            'cases' => [],
            'validation_errors' => [],
            'csv_rows' => 0,
            'skipped_blank_rows' => 0,
            'fields_present_counts' => $this->emptyFieldCounts(),
            'profile_snapshot_count' => 0,
            'source_context_count' => 0,
        ];

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $result['validation_errors'][] = [
                'row_number' => null,
                'error_codes' => ['csv_unreadable'],
            ];

            return $result;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $result['validation_errors'][] = [
                'row_number' => 1,
                'error_codes' => ['header_missing'],
            ];

            return $result;
        }

        $header = $this->normalizeHeader($header);
        $headerErrors = $this->headerErrors($header);
        if ($headerErrors !== []) {
            fclose($handle);
            $result['validation_errors'][] = [
                'row_number' => 1,
                'error_codes' => $headerErrors,
            ];

            return $result;
        }

        $headerMap = array_flip($header);
        $rowNumber = 1;
        while (($raw = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $result['csv_rows']++;

            $row = $this->csvRow($headerMap, $raw);
            if ($this->isBlankRow($row)) {
                $result['skipped_blank_rows']++;
                continue;
            }

            $errors = $this->validateRow($row);
            if ($errors !== []) {
                $result['validation_errors'][] = [
                    'row_number' => $rowNumber,
                    'error_codes' => $errors,
                ];
                continue;
            }

            $case = $this->caseFromRow($row);
            $result['cases'][] = $case;
            foreach (array_keys($case['parser_expected_fields']) as $field) {
                $result['fields_present_counts'][$field] = ($result['fields_present_counts'][$field] ?? 0) + 1;
            }
            if (array_key_exists('expected_profile_snapshot', $case)) {
                $result['profile_snapshot_count']++;
            }
            if (array_key_exists('source_context', $case)) {
                $result['source_context_count']++;
            }
        }

        fclose($handle);

        return $result;
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string>
     */
    private function normalizeHeader(array $header): array
    {
        return array_values(array_map(
            function (mixed $column, int $index): string {
                $value = trim((string) $column);
                if ($index === 0) {
                    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
                }

                return $value;
            },
            $header,
            array_keys($header)
        ));
    }

    /**
     * @param  list<string>  $header
     * @return list<string>
     */
    private function headerErrors(array $header): array
    {
        $errors = [];
        foreach (self::COLUMNS as $column) {
            if (! in_array($column, $header, true)) {
                $errors[] = 'missing_column_'.$column;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, int>  $headerMap
     * @param  list<string|null>  $raw
     * @return array<string, string>
     */
    private function csvRow(array $headerMap, array $raw): array
    {
        $row = [];
        foreach (self::COLUMNS as $column) {
            $index = $headerMap[$column] ?? null;
            $row[$column] = $index === null ? '' : trim((string) ($raw[$index] ?? ''));
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validateRow(array $row): array
    {
        $errors = [];
        foreach (['case_id', 'layout_type', 'language', 'ocr_text'] as $column) {
            if ($row[$column] === '') {
                $errors[] = $column.'_missing';
            }
        }

        if ($this->parserExpectedFields($row) === []) {
            $errors[] = 'parser_expected_fields_missing';
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function caseFromRow(array $row): array
    {
        $case = [
            'case_id' => $row['case_id'],
            'layout_type' => $row['layout_type'],
            'language' => $row['language'],
            'ocr_text' => $row['ocr_text'],
            'parser_expected_fields' => $this->parserExpectedFields($row),
        ];

        $snapshot = $this->profileSnapshot($row);
        if ($snapshot !== []) {
            $case['expected_profile_snapshot'] = $snapshot;
        }

        $sourceContext = $this->sourceContext($row);
        if ($sourceContext !== []) {
            $case['source_context'] = $sourceContext;
        }

        if ($row['curator_notes'] !== '') {
            $case['notes'] = $row['curator_notes'];
        }

        return $case;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function parserExpectedFields(array $row): array
    {
        $fields = [];
        foreach (self::PARSER_FIELD_COLUMNS as $column => $field) {
            if (($row[$column] ?? '') !== '') {
                $fields[$field] = $row[$column];
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function profileSnapshot(array $row): array
    {
        $snapshot = [];
        $core = $this->compactStrings([
            'full_name' => $row['profile_full_name'],
            'date_of_birth' => $row['profile_date_of_birth'],
            'birth_time' => $row['profile_birth_time'],
            'birth_place' => $row['profile_birth_place'],
            'birth_place_raw' => $row['birth_place_raw'],
            'height_text' => $row['profile_height_text'],
            'education' => $row['profile_education'],
            'occupation' => $row['profile_occupation'],
            'religion' => $row['profile_religion'],
            'caste' => $row['profile_caste'],
            'sub_caste' => $row['profile_sub_caste'],
            'primary_contact_number' => $row['profile_primary_contact_number'],
        ]);
        if ($core !== []) {
            $snapshot['core'] = $core;
        }

        $contacts = [];
        if ($row['profile_primary_contact_number'] !== '') {
            $contacts[] = [
                'type' => 'primary_communication_contact',
                'number' => $row['profile_primary_contact_number'],
                'is_primary' => true,
            ];
        }
        if ($row['profile_document_contacts'] !== '') {
            $contacts[] = [
                'type' => 'document_contact',
                'raw' => $row['profile_document_contacts'],
                'is_primary' => false,
            ];
        }
        if ($contacts !== []) {
            $snapshot['contacts'] = $contacts;
        }

        $addresses = array_values(array_filter([
            $this->addressFromRow($row, 'current', 'current_residence'),
            $this->addressFromRow($row, 'native', 'native_place'),
        ]));
        if ($addresses !== []) {
            $snapshot['addresses'] = $addresses;
        }

        $family = $this->compactStrings([
            'father_name' => $row['father_name'],
            'mother_name' => $row['mother_name'],
            'brother_notes' => $row['brother_notes'],
            'sister_notes' => $row['sister_notes'],
            'family_notes' => $row['family_notes'],
        ]);
        if ($family !== []) {
            $snapshot['family'] = $family;
        }

        if ($row['relatives_notes'] !== '') {
            $snapshot['relatives'] = [
                [
                    'relation_type' => 'notes',
                    'relative_details' => $row['relatives_notes'],
                ],
            ];
        }

        if ($row['property_notes'] !== '') {
            $snapshot['property'] = [
                'summary' => $row['property_notes'],
            ];
        }

        if ($row['expectations_notes'] !== '') {
            $snapshot['expectations'] = [
                'notes' => $row['expectations_notes'],
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>|null
     */
    private function addressFromRow(array $row, string $prefix, string $type): ?array
    {
        $address = $this->compactStrings([
            'type' => $type,
            'address_line' => $row[$prefix.'_address_raw'],
            'village_or_city' => $row[$prefix.'_village_or_city'],
            'taluka' => $row[$prefix.'_taluka'],
            'district' => $row[$prefix.'_district'],
            'state' => $row[$prefix.'_state'],
        ]);

        return count($address) > 1 ? $address : null;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function sourceContext(array $row): array
    {
        return $this->compactStrings([
            'primary_contact_rule' => $row['source_primary_contact_rule'],
            'consent_note' => $row['source_consent_note'],
        ]);
    }

    /**
     * @param  array<string, string>  $items
     * @return array<string, string>
     */
    private function compactStrings(array $items): array
    {
        return array_filter(
            $items,
            fn (string $value): bool => trim($value) !== ''
        );
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     */
    private function jsonl(array $cases): string
    {
        return collect($cases)
            ->map(fn (array $case): string => json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode(PHP_EOL).PHP_EOL;
    }

    /**
     * @param  array{cases: list<array<string, mixed>>, validation_errors: list<array<string, mixed>>, csv_rows: int, skipped_blank_rows: int, fields_present_counts: array<string, int>, profile_snapshot_count: int, source_context_count: int}  $loaded
     * @return array<string, mixed>
     */
    private function summary(array $loaded, bool $written = true): array
    {
        return [
            'csv_rows' => $loaded['csv_rows'],
            'cases_written' => $written ? count($loaded['cases']) : 0,
            'skipped_blank_rows' => $loaded['skipped_blank_rows'],
            'fields_present_counts' => $loaded['fields_present_counts'],
            'profile_snapshot_count' => $loaded['profile_snapshot_count'],
            'source_context_count' => $loaded['source_context_count'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'csv_rows' => 0,
            'cases_written' => 0,
            'skipped_blank_rows' => 0,
            'fields_present_counts' => $this->emptyFieldCounts(),
            'profile_snapshot_count' => 0,
            'source_context_count' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyFieldCounts(): array
    {
        return array_fill_keys(array_values(self::PARSER_FIELD_COLUMNS), 0);
    }

    private function safeOutputDisplay(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $storage = str_replace('\\', '/', storage_path('app'));

        if (str_starts_with($normalizedPath, $storage.'/')) {
            return 'storage/app/'.substr($normalizedPath, strlen($storage) + 1);
        }

        return basename($path);
    }
}
