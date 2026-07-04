<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class IntakeGoldenDatasetCsvTemplateCommand extends Command
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

    protected $signature = 'intake:golden-dataset-csv-template
        {--output=storage/app/intake-golden-datasets/golden-curation-template.csv : Output CSV path under storage/app/intake-golden-datasets}
        {--rows=20 : Blank rows to create}
        {--force : Overwrite if file exists}
        {--json : Print JSON}';

    protected $description = 'Create a private CSV curation template for golden OCR regression datasets.';

    public function handle(): int
    {
        $outputOption = $this->outputOption();
        $rows = $this->rowsOption();
        if ($rows === false) {
            return $this->failReport(
                output: $outputOption,
                code: 'rows_invalid',
                message: 'Rows must be a number from 1 to 500.'
            );
        }

        $path = $this->resolvePrivatePath($outputOption);
        if ($path === false) {
            return $this->failReport(
                output: $outputOption,
                code: 'output_path_not_allowed',
                message: 'Output path is not allowed. Use storage/app/intake-golden-datasets/... only.'
            );
        }

        if (File::exists($path) && ! (bool) $this->option('force')) {
            return $this->failReport(
                output: $this->safeOutputDisplay($path),
                code: 'output_file_exists',
                message: 'Output file already exists. Re-run with --force to overwrite it.'
            );
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->csvTemplate($rows));

        $report = [
            'success' => true,
            'output_path' => $this->safeOutputDisplay($path),
            'summary' => [
                'blank_rows' => $rows,
                'column_count' => count(self::COLUMNS),
            ],
            'convert_command' => 'php artisan intake:golden-dataset-csv-to-jsonl --csv='.$this->safeOutputDisplay($path),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Private golden dataset CSV template written.');
        $this->line('Output: '.$report['output_path']);
        $this->line('Blank rows: '.$report['summary']['blank_rows']);
        $this->line('Columns: '.$report['summary']['column_count']);
        $this->line('Convert: '.$report['convert_command']);

        return self::SUCCESS;
    }

    private function outputOption(): string
    {
        $value = trim((string) $this->option('output'));

        return $value === '' ? self::OUTPUT_DIR.'/golden-curation-template.csv' : $value;
    }

    private function rowsOption(): int|false
    {
        $value = trim((string) $this->option('rows'));
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return false;
        }

        $rows = (int) $value;

        return $rows >= 1 && $rows <= 500 ? $rows : false;
    }

    private function failReport(string $output, string $code, string $message): int
    {
        $report = [
            'success' => false,
            'output_path' => $output,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);
        $this->line('Allowed output example: php artisan intake:golden-dataset-csv-template --output=storage/app/intake-golden-datasets/golden-curation-template.csv');

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

    private function csvTemplate(int $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::COLUMNS);
        $blank = array_fill(0, count(self::COLUMNS), '');
        for ($i = 0; $i < $rows; $i++) {
            fputcsv($handle, $blank);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
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
