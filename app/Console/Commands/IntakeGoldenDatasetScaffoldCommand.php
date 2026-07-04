<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class IntakeGoldenDatasetScaffoldCommand extends Command
{
    private const OUTPUT_DIR = 'storage/app/intake-golden-datasets';

    protected $signature = 'intake:golden-dataset-scaffold
        {--output=storage/app/intake-golden-datasets/golden.example.jsonl : Output JSONL path under storage/app}
        {--force : Overwrite if file exists}
        {--json : Print JSON}';

    protected $description = 'Create a private synthetic golden dataset scaffold under storage/app for offline OCR regression curation.';

    public function handle(): int
    {
        $outputOption = $this->outputOption();
        $path = $this->resolveOutputPath($outputOption);

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

        $cases = $this->syntheticCases();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->jsonl($cases));

        $report = [
            'success' => true,
            'output_path' => $this->safeOutputDisplay($path),
            'summary' => [
                'synthetic_case_count' => count($cases),
                'layout_types' => array_values(array_unique(array_map(
                    fn (array $case): string => (string) $case['layout_type'],
                    $cases
                ))),
            ],
            'regression_command' => 'php artisan intake:ocr-regression --dataset='.$this->safeOutputDisplay($path),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Synthetic golden dataset scaffold written.');
        $this->line('Output: '.$report['output_path']);
        $this->line('Synthetic cases: '.$report['summary']['synthetic_case_count']);
        $this->line('Run regression: '.$report['regression_command']);

        return self::SUCCESS;
    }

    private function outputOption(): string
    {
        $value = trim((string) $this->option('output'));

        return $value === '' ? self::OUTPUT_DIR.'/golden.example.jsonl' : $value;
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
        $this->line('Allowed output example: php artisan intake:golden-dataset-scaffold --output=storage/app/intake-golden-datasets/golden.example.jsonl');

        return self::FAILURE;
    }

    /**
     * @return string|false
     */
    private function resolveOutputPath(string $output): string|false
    {
        $normalized = str_replace('\\', '/', trim($output));
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
     * @return list<array<string, mixed>>
     */
    private function syntheticCases(): array
    {
        return [
            [
                'case_id' => 'synthetic_private_scaffold_single_column',
                'layout_type' => 'single_column',
                'language' => 'en',
                'ocr_text' => "Name: Synthetic Alpha\nDate of Birth: 1996-04-12\nHeight: 5 ft 7 in\nEducation: B.Sc\nOccupation: Teacher\nMobile: 5550101001\nAddress: Sample Lane, Test City\nReligion: Hindu\nCaste: Maratha",
                'expected_fields' => [
                    'full_name' => 'Synthetic Alpha',
                    'date_of_birth' => '1996-04-12',
                ],
                'notes' => 'Synthetic example only. Replace with manually reviewed private cases under storage/app.',
            ],
            [
                'case_id' => 'synthetic_private_scaffold_two_column',
                'layout_type' => 'two_column',
                'language' => 'en',
                'ocr_text' => "Name: Synthetic Beta\nDOB: 1994-08-05\nEducation: B.Com\nOccupation: Clerk\nMobile: 5550101002\nAddress: Example Nagar, Test District",
                'expected_fields' => [
                    'full_name' => 'Synthetic Beta',
                    'date_of_birth' => '1994-08-05',
                ],
                'notes' => 'Synthetic two-column-style example only.',
            ],
            [
                'case_id' => 'synthetic_private_scaffold_marathi_mixed',
                'layout_type' => 'marathi_or_mixed_text',
                'language' => 'mr-en',
                'ocr_text' => "Name: Synthetic Gamma\n\u{0928}\u{093E}\u{0935}: Synthetic Gamma\nDate of Birth: 1995-03-22\n\u{0936}\u{093F}\u{0915}\u{094D}\u{0937}\u{0923}: B.A.\nMobile: 5550101003\nAddress: Sample Peth, Test Pune",
                'expected_fields' => [
                    'full_name' => 'Synthetic Gamma',
                    'date_of_birth' => '1995-03-22',
                ],
                'notes' => 'Synthetic Marathi/mixed-text example only.',
            ],
        ];
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
