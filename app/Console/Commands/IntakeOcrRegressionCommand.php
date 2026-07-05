<?php

namespace App\Console\Commands;

use App\Services\BiodataParserService;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class IntakeOcrRegressionCommand extends Command
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

    private const OPTIONAL_FIELDS = [
        'notes',
        'source_label',
        'image_hash',
        'expected_snapshot',
        'parser_expected_fields',
        'expected_profile_snapshot',
        'source_context',
    ];

    private const FIELD_PATHS = [
        'full_name' => ['core.full_name', 'core.name', 'candidate.full_name', 'candidate.name'],
        'date_of_birth' => ['core.date_of_birth', 'core.dob', 'core.birth_date', 'candidate.date_of_birth', 'candidate.dob'],
        'height' => ['core.height_cm', 'core.height', 'core.height_text'],
        'education' => ['core.highest_education', 'core.education', 'core.education_level', 'education_history.0.degree', 'education_history.0.qualification', 'education_history.0.course', 'education_history.0.course_name'],
        'occupation' => ['core.occupation_title', 'core.occupation', 'core.profession', 'career_history.0.occupation_title', 'career_history.0.designation', 'career_history.0.job_title', 'career_history.0.role'],
        'primary_contact_number' => ['core.primary_contact_number', 'candidate.primary_contact_number'],
        'document_contact_number' => ['core.document_contact_number', 'core.document_contact_numbers', 'document_contact_number', 'document_contact_numbers', 'contacts'],
        'address' => ['addresses.0.address_line', 'addresses.0.raw', 'self_addresses.0.address_line', 'parents_addresses.0.address_line', 'core.address_line', 'core.address', 'core.current_address', 'core.permanent_address'],
        'religion' => ['core.religion', 'core.religion_label', 'core.religion_id'],
        'caste' => ['core.caste', 'core.caste_label', 'core.caste_id'],
        'sub_caste' => ['core.sub_caste', 'core.sub_caste_label', 'core.sub_caste_id', 'candidate.sub_caste'],
    ];

    private const PLACEHOLDER_VALUES = [
        '-',
        '--',
        '—',
        '–',
        '...',
        'n/a',
        'na',
        'nil',
        'none',
        'not applicable',
        'not available',
        'null',
        'pending',
        'tbd',
        'unknown',
    ];

    protected $signature = 'intake:ocr-regression
        {--dataset= : Dataset JSONL/JSON file path under storage/app or absolute local path}
        {--json : Print JSON}
        {--field= : Optional field filter}
        {--limit=500 : Maximum cases to inspect}
        {--fail-under= : Optional minimum overall accuracy percentage}
        {--fail-under-field=* : Optional repeatable minimum field accuracy threshold as field:percent}';

    protected $description = 'Read-only offline parser regression against a golden OCR text dataset.';

    public function __construct(
        private readonly BiodataParserService $parser,
        private readonly IntakeParsedSnapshotSkeleton $skeleton,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataset = $this->datasetOption();
        $field = $this->fieldOption();
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $failUnder = $this->failUnderOption();
        $fieldThresholds = $this->failUnderFieldOptions();

        if ($field === false || $failUnder === false || $fieldThresholds === false) {
            return self::FAILURE;
        }

        if ($dataset === null) {
            return $this->missingDataset('Dataset file is required.');
        }

        $path = $this->resolveDatasetPath($dataset);
        if ($path === false) {
            return $this->invalidDatasetPath($dataset);
        }

        if ($path === null || ! File::exists($path)) {
            return $this->missingDataset('Dataset file was not found.');
        }

        $loaded = $this->loadDataset($path, $limit);
        $fields = $field === null ? self::FIELDS : [$field];
        $rows = [];
        $fieldStats = $this->emptyFieldStats($fields);
        $layoutStats = [];
        $validCases = 0;

        foreach ($loaded['cases'] as $case) {
            $validCases++;
            $parsed = $this->parseText((string) $case['ocr_text']);
            $evaluation = $this->evaluateCase($case, $parsed, $fields);
            $rows[] = $evaluation['row'];
            $this->mergeFieldStats($fieldStats, $evaluation['field_stats']);
            $this->mergeLayoutStats($layoutStats, (string) $case['layout_type'], $evaluation['row']);
        }

        $fieldAccuracy = $this->fieldAccuracy($fieldStats);
        $layoutAccuracy = $this->layoutAccuracy($layoutStats);
        if ($this->shouldRedactCaseIds($path)) {
            $rows = $this->redactRowCaseIds($rows);
            $loaded['schema_errors'] = $this->redactSchemaErrorCaseIds($loaded['schema_errors']);
        }
        $summary = $this->summary(
            loadedTotal: $loaded['total_loaded'],
            validCases: $validCases,
            invalidCases: count($loaded['schema_errors']),
            fieldAccuracy: $fieldAccuracy,
            rows: $rows,
            failUnder: $failUnder
        );
        $thresholdReport = $this->thresholdReport($summary, $fieldAccuracy, $failUnder, $fieldThresholds);
        if ($summary['regression_status'] === 'pass' && $thresholdReport['threshold_status'] === 'fail') {
            $summary['regression_status'] = 'fail_under_threshold';
        }
        $summary['threshold_status'] = $thresholdReport['threshold_status'];

        $report = [
            'success' => $summary['regression_status'] === 'pass',
            'filters' => [
                'dataset' => $this->safeDatasetDisplay($path),
                'field' => $field,
                'limit' => $limit,
                'fail_under' => $failUnder,
                'fail_under_field' => $this->fieldThresholdFilterDisplay($fieldThresholds),
            ],
            'summary' => $summary,
            'threshold_status' => $thresholdReport['threshold_status'],
            'overall_threshold' => $thresholdReport['overall_threshold'],
            'field_thresholds' => $thresholdReport['field_thresholds'],
            'threshold_failures' => $thresholdReport['threshold_failures'],
            'field_accuracy' => $fieldAccuracy,
            'layout_accuracy' => $layoutAccuracy,
            'rows' => $rows,
            'schema_errors' => $loaded['schema_errors'],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderReport($report);
        }

        return $summary['regression_status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }

    private function missingDataset(string $message): int
    {
        $report = [
            'success' => false,
            'filters' => [
                'dataset' => $this->datasetOption(),
                'field' => null,
                'limit' => max(1, min(5000, (int) $this->option('limit'))),
                'fail_under' => null,
                'fail_under_field' => [],
            ],
            'summary' => [
                'total_cases' => 0,
                'valid_cases' => 0,
                'invalid_cases' => 0,
                'total_expected_fields' => 0,
                'exact_match_count' => 0,
                'mismatch_count' => 0,
                'missing_count' => 0,
                'overall_accuracy_percent' => 0.0,
                'regression_status' => 'no_dataset',
                'threshold_status' => 'fail',
            ],
            'threshold_status' => 'fail',
            'overall_threshold' => null,
            'field_thresholds' => [],
            'threshold_failures' => [],
            'field_accuracy' => [],
            'layout_accuracy' => [],
            'rows' => [],
            'schema_errors' => [
                [
                    'line' => null,
                    'case_id' => null,
                    'error_codes' => ['dataset_missing'],
                    'message' => $message,
                ],
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);
        $this->line('Example dataset path: storage/app/intake-golden-datasets/golden.jsonl');
        $this->line('Run: php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl');

        return self::FAILURE;
    }

    private function invalidDatasetPath(string $dataset): int
    {
        $message = 'Dataset path is not allowed. Use storage/app/..., tests/Fixtures/..., tests/fixtures/..., or an absolute local path.';
        $report = [
            'success' => false,
            'filters' => [
                'dataset' => $dataset,
                'field' => null,
                'limit' => max(1, min(5000, (int) $this->option('limit'))),
                'fail_under' => null,
                'fail_under_field' => [],
            ],
            'summary' => [
                'total_cases' => 0,
                'valid_cases' => 0,
                'invalid_cases' => 0,
                'total_expected_fields' => 0,
                'exact_match_count' => 0,
                'mismatch_count' => 0,
                'missing_count' => 0,
                'overall_accuracy_percent' => 0.0,
                'regression_status' => 'no_dataset',
                'threshold_status' => 'fail',
            ],
            'threshold_status' => 'fail',
            'overall_threshold' => null,
            'field_thresholds' => [],
            'threshold_failures' => [],
            'field_accuracy' => [],
            'layout_accuracy' => [],
            'rows' => [],
            'schema_errors' => [
                [
                    'line' => null,
                    'case_id' => null,
                    'error_codes' => ['dataset_path_not_allowed'],
                    'message' => $message,
                ],
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);
        $this->line('Synthetic fixture: php artisan intake:ocr-regression --dataset=tests/Fixtures/Intake/golden_dataset_minimal.jsonl');
        $this->line('Private dataset: php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl');

        return self::FAILURE;
    }

    private function datasetOption(): ?string
    {
        $value = trim((string) $this->option('dataset'));

        return $value === '' ? null : $value;
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

    private function failUnderOption(): float|false|null
    {
        $value = trim((string) $this->option('fail-under'));
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            $this->error('Invalid --fail-under value. Use a number from 0 to 100.');

            return false;
        }

        return max(0.0, min(100.0, (float) $value));
    }

    /**
     * @return array<string, float>|false
     */
    private function failUnderFieldOptions(): array|false
    {
        $values = $this->option('fail-under-field');
        if ($values === null || $values === false || $values === '') {
            return [];
        }

        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            $this->error('Invalid --fail-under-field value. Use field:percent, for example address:77.');

            return false;
        }

        $thresholds = [];
        foreach ($values as $rawValue) {
            $value = trim((string) $rawValue);
            if ($value === '') {
                continue;
            }

            if (! preg_match('/^([A-Za-z0-9_]+)\s*:\s*([0-9]+(?:\.[0-9]+)?)$/', $value, $matches)) {
                $this->error('Invalid --fail-under-field value. Use field:percent, for example address:77.');

                return false;
            }

            $field = Str::of($matches[1])->trim()->lower()->toString();
            if (! in_array($field, self::FIELDS, true)) {
                $this->error('Invalid --fail-under-field field "'.$field.'". Allowed: '.implode(', ', self::FIELDS));

                return false;
            }

            $threshold = (float) $matches[2];
            if ($threshold < 0.0 || $threshold > 100.0) {
                $this->error('Invalid --fail-under-field percentage. Use a number from 0 to 100.');

                return false;
            }

            $thresholds[$field] = $threshold;
        }

        return $thresholds;
    }

    private function resolveDatasetPath(string $path): string|false|null
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, 'storage/app/')) {
            return storage_path('app/'.substr($normalized, strlen('storage/app/')));
        }

        if (str_starts_with($normalized, 'tests/Fixtures/')) {
            return base_path($normalized);
        }
        if (str_starts_with($normalized, 'tests/fixtures/')) {
            $candidate = base_path($normalized);
            if (File::exists($candidate)) {
                return $candidate;
            }

            return base_path('tests/Fixtures/'.substr($normalized, strlen('tests/fixtures/')));
        }

        return false;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    /**
     * @return array{cases: list<array<string, mixed>>, schema_errors: list<array<string, mixed>>, total_loaded: int}
     */
    private function loadDataset(string $path, int $limit): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === 'json'
            ? $this->loadJsonDataset($path, $limit)
            : $this->loadJsonlDataset($path, $limit);
    }

    /**
     * @return array{cases: list<array<string, mixed>>, schema_errors: list<array<string, mixed>>, total_loaded: int}
     */
    private function loadJsonlDataset(string $path, int $limit): array
    {
        $cases = [];
        $errors = [];
        $lineNumber = 0;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [
                'cases' => [],
                'schema_errors' => [[
                    'line' => null,
                    'case_id' => null,
                    'error_codes' => ['dataset_unreadable'],
                    'message' => 'Dataset file could not be opened.',
                ]],
                'total_loaded' => 0,
            ];
        }

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            if (count($cases) >= $limit) {
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = [
                    'line' => $lineNumber,
                    'case_id' => null,
                    'error_codes' => ['invalid_json'],
                    'message' => 'Invalid JSONL row.',
                ];
                continue;
            }

            $validated = $this->validateCase($decoded, $lineNumber);
            if ($validated['errors'] !== []) {
                $errors[] = [
                    'line' => $lineNumber,
                    'case_id' => $validated['case_id'],
                    'error_codes' => $validated['errors'],
                    'message' => 'Dataset row failed schema validation.',
                ];
                continue;
            }

            $cases[] = $validated['case'];
        }

        fclose($handle);

        return [
            'cases' => $cases,
            'schema_errors' => $errors,
            'total_loaded' => count($cases) + count($errors),
        ];
    }

    /**
     * @return array{cases: list<array<string, mixed>>, schema_errors: list<array<string, mixed>>, total_loaded: int}
     */
    private function loadJsonDataset(string $path, int $limit): array
    {
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return [
                'cases' => [],
                'schema_errors' => [[
                    'line' => null,
                    'case_id' => null,
                    'error_codes' => ['invalid_json'],
                    'message' => 'Dataset JSON file is invalid.',
                ]],
                'total_loaded' => 1,
            ];
        }

        $items = array_is_list($decoded) ? $decoded : ($decoded['cases'] ?? []);
        if (! is_array($items)) {
            $items = [];
        }

        $cases = [];
        $errors = [];
        foreach (array_slice($items, 0, $limit, true) as $index => $item) {
            if (! is_array($item)) {
                $errors[] = [
                    'line' => is_int($index) ? $index + 1 : null,
                    'case_id' => null,
                    'error_codes' => ['case_not_object'],
                    'message' => 'Dataset case must be an object.',
                ];
                continue;
            }

            $validated = $this->validateCase($item, is_int($index) ? $index + 1 : null);
            if ($validated['errors'] !== []) {
                $errors[] = [
                    'line' => is_int($index) ? $index + 1 : null,
                    'case_id' => $validated['case_id'],
                    'error_codes' => $validated['errors'],
                    'message' => 'Dataset row failed schema validation.',
                ];
                continue;
            }

            $cases[] = $validated['case'];
        }

        return [
            'cases' => $cases,
            'schema_errors' => $errors,
            'total_loaded' => count($cases) + count($errors),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{case: array<string, mixed>, errors: list<string>, case_id: ?string}
     */
    private function validateCase(array $row, ?int $line): array
    {
        $errors = [];
        $caseId = $this->safeScalarString($row['case_id'] ?? null);
        $layoutType = $this->safeScalarString($row['layout_type'] ?? null);
        $language = $this->safeScalarString($row['language'] ?? null);
        $ocrText = $this->safeScalarString($row['ocr_text'] ?? null);
        $expectedFieldsSource = array_key_exists('parser_expected_fields', $row)
            ? 'parser_expected_fields'
            : 'expected_fields';
        $expectedFields = $row[$expectedFieldsSource] ?? null;
        $expectedProfileSnapshot = $row['expected_profile_snapshot'] ?? null;
        $sourceContext = $row['source_context'] ?? null;

        foreach (['case_id' => $caseId, 'layout_type' => $layoutType, 'language' => $language, 'ocr_text' => $ocrText] as $key => $value) {
            if ($value === null || trim($value) === '') {
                $errors[] = $key.'_missing';
            }
        }

        if (! is_array($expectedFields)) {
            $errors[] = $expectedFieldsSource === 'parser_expected_fields'
                ? 'parser_expected_fields_not_array'
                : 'expected_fields_missing';
            $expectedFields = [];
        }

        if (array_key_exists('expected_profile_snapshot', $row) && ! is_array($expectedProfileSnapshot)) {
            $errors[] = 'expected_profile_snapshot_not_array';
            $expectedProfileSnapshot = null;
        }

        if (array_key_exists('source_context', $row) && ! is_array($sourceContext)) {
            $errors[] = 'source_context_not_array';
            $sourceContext = null;
        }

        foreach (array_keys($row) as $key) {
            if (! in_array($key, array_merge(['case_id', 'layout_type', 'language', 'ocr_text', 'expected_fields'], self::OPTIONAL_FIELDS), true)) {
                $errors[] = 'unknown_key_'.$this->safeToken((string) $key, 'unknown');
            }
        }

        $normalizedExpected = [];
        foreach ($expectedFields as $field => $value) {
            $field = $this->safeToken((string) $field, '');
            if (! in_array($field, self::FIELDS, true)) {
                $errors[] = 'unknown_expected_field_'.$field;
                continue;
            }
            if ($value !== null && ! is_scalar($value)) {
                $errors[] = 'expected_field_not_scalar_'.$field;
                continue;
            }
            $normalizedExpected[$field] = $value === null ? null : (string) $value;
        }

        return [
            'case' => [
                'case_id' => (string) $caseId,
                'layout_type' => (string) $layoutType,
                'language' => (string) $language,
                'ocr_text' => (string) $ocrText,
                'expected_fields' => $normalizedExpected,
                'expected_fields_source' => $expectedFieldsSource,
                'profile_snapshot_metadata' => $this->profileSnapshotMetadata(is_array($expectedProfileSnapshot) ? $expectedProfileSnapshot : null),
                'source_context_metadata' => $this->sourceContextMetadata(is_array($sourceContext) ? $sourceContext : null),
                '_line' => $line,
            ],
            'errors' => array_values(array_unique($errors)),
            'case_id' => $caseId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseText(string $text): array
    {
        $parsed = $this->parser->parse($text);

        return $this->skeleton->ensure($parsed);
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $fields
     * @return array{row: array<string, mixed>, field_stats: array<string, array{total_expected: int, exact_match_count: int, mismatch_count: int, missing_count: int}>}
     */
    private function evaluateCase(array $case, array $parsed, array $fields): array
    {
        $fieldStats = $this->emptyFieldStats($fields);
        $mismatchFields = [];
        $missingFields = [];
        $exact = 0;
        $expectedCount = 0;

        foreach ($fields as $field) {
            $expectedRaw = $case['expected_fields'][$field] ?? null;
            $expected = $this->normalizeForComparison($field, $expectedRaw);
            if ($expected === null) {
                continue;
            }

            $expectedCount++;
            $fieldStats[$field]['total_expected']++;
            $actualRaw = $this->firstParsedFieldValue($parsed, $field);
            $actual = $this->normalizeForComparison($field, $actualRaw);

            if ($actual === null) {
                $missingFields[] = $field;
                $fieldStats[$field]['missing_count']++;
                continue;
            }

            if ($this->valuesMatchForComparison($field, $expected, $actual)) {
                $exact++;
                $fieldStats[$field]['exact_match_count']++;
                continue;
            }

            $mismatchFields[] = $field;
            $fieldStats[$field]['mismatch_count']++;
        }

        $status = 'pass';
        if ($expectedCount === 0) {
            $status = 'no_expected_fields';
        } elseif ($missingFields !== [] || $mismatchFields !== []) {
            $status = 'fail';
        }

        return [
            'row' => [
                'case_id' => (string) $case['case_id'],
                'layout_type' => (string) $case['layout_type'],
                'language' => (string) $case['language'],
                'fields_expected_count' => $expectedCount,
                'exact_match_count' => $exact,
                'mismatch_fields' => $mismatchFields,
                'missing_fields' => $missingFields,
                'profile_snapshot_present' => (bool) data_get($case, 'profile_snapshot_metadata.profile_snapshot_present', false),
                'profile_snapshot_sections' => data_get($case, 'profile_snapshot_metadata.profile_snapshot_sections', []),
                'address_count' => data_get($case, 'profile_snapshot_metadata.address_count'),
                'contact_count' => data_get($case, 'profile_snapshot_metadata.contact_count'),
                'family_section_present' => (bool) data_get($case, 'profile_snapshot_metadata.family_section_present', false),
                'source_context_present' => (bool) data_get($case, 'source_context_metadata.source_context_present', false),
                'source_context_keys' => data_get($case, 'source_context_metadata.source_context_keys', []),
                'status' => $status,
            ],
            'field_stats' => $fieldStats,
        ];
    }

    private function valuesMatchForComparison(string $field, string $expected, string $actual): bool
    {
        if ($actual === $expected) {
            return true;
        }

        if ($field !== 'address') {
            return false;
        }

        if ($this->addressComponentsMatchForComparison($expected, $actual)) {
            return true;
        }

        if ($this->addressLocationComponentsMatchForComparison($expected, $actual)) {
            return true;
        }

        if ($this->addressLocationComponentsMatchWithShortMarkerGap($expected, $actual)) {
            return true;
        }

        $expectedLength = mb_strlen($expected);
        $actualLength = mb_strlen($actual);
        $shorter = $expectedLength <= $actualLength ? $expected : $actual;
        $longer = $expectedLength <= $actualLength ? $actual : $expected;
        $shorterLength = min($expectedLength, $actualLength);
        $longerLength = max($expectedLength, $actualLength);
        $tokenCount = count(preg_split('/\s+/u', $shorter, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return $shorterLength >= 20
            && $tokenCount >= 3
            && $longerLength > 0
            && ($shorterLength / $longerLength) >= 0.65
            && str_contains($longer, $shorter);
    }

    private function addressComponentsMatchForComparison(string $expected, string $actual): bool
    {
        $expectedTokens = $this->addressComparisonTokens($expected);
        $actualTokens = $this->addressComparisonTokens($actual);

        if (count($expectedTokens) < 3 || count($actualTokens) < 3) {
            return false;
        }

        if (count($expectedTokens) !== count($actualTokens)) {
            return false;
        }

        sort($expectedTokens, SORT_STRING);
        sort($actualTokens, SORT_STRING);

        return $expectedTokens === $actualTokens;
    }

    private function addressLocationComponentsMatchWithShortMarkerGap(string $expected, string $actual): bool
    {
        $expectedTokens = $this->addressLocationComparisonTokens($expected);
        $actualTokens = $this->addressLocationComparisonTokens($actual);

        if (count($expectedTokens) < 8 || count($actualTokens) < 8) {
            return false;
        }

        if (count($actualTokens) >= count($expectedTokens)) {
            return false;
        }

        $missingFromActual = $this->addressTokenMultisetDifference($expectedTokens, $actualTokens);
        $extraInActual = $this->addressTokenMultisetDifference($actualTokens, $expectedTokens);

        if ($extraInActual !== [] || $missingFromActual === [] || count($missingFromActual) > 2) {
            return false;
        }

        if ((count($actualTokens) / count($expectedTokens)) < 0.88) {
            return false;
        }

        foreach ($missingFromActual as $token) {
            if (! $this->isShortAddressMarkerCandidate($token)) {
                return false;
            }
        }

        return true;
    }

    private function addressLocationComponentsMatchForComparison(string $expected, string $actual): bool
    {
        $expectedTokens = $this->addressLocationComparisonTokens($expected);
        $actualTokens = $this->addressLocationComparisonTokens($actual);

        if (count($expectedTokens) < 3 || count($actualTokens) < 3) {
            return false;
        }

        if (count($expectedTokens) !== count($actualTokens)) {
            return false;
        }

        sort($expectedTokens, SORT_STRING);
        sort($actualTokens, SORT_STRING);

        return $expectedTokens === $actualTokens;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function addressTokenMultisetDifference(array $left, array $right): array
    {
        $rightCounts = array_count_values($right);
        $missing = [];

        foreach ($left as $token) {
            if (($rightCounts[$token] ?? 0) > 0) {
                $rightCounts[$token]--;
                continue;
            }

            $missing[] = $token;
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function addressComparisonTokens(string $address): array
    {
        $tokens = preg_split('/\s+/u', $address, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_map(
            fn (string $token): string => $this->canonicalAddressComparisonToken($token),
            $tokens
        );

        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => $token !== ''
        ));
    }

    /**
     * @return list<string>
     */
    private function addressLocationComparisonTokens(string $address): array
    {
        return array_values(array_filter(
            $this->addressComparisonTokens($address),
            fn (string $token): bool => ! $this->isAddressComparisonLabelToken($token)
        ));
    }

    private function canonicalAddressComparisonToken(string $token): string
    {
        return match ($token) {
            'dist', 'distt', 'district', 'जि', 'जिल्हा' => 'district',
            'tal', 'taluka', 'tq', 'ता', 'तालुका' => 'taluka',
            'vill', 'village', 'gaon', 'गाव' => 'village',
            default => $token,
        };
    }

    private function isAddressComparisonLabelToken(string $token): bool
    {
        return in_array($token, [
            'address',
            'at',
            'birth',
            'contact',
            'current',
            'district',
            'native',
            'pin',
            'pincode',
            'place',
            'po',
            'post',
            'residence',
            'taluka',
            'village',
            'मु',
            'मू',
            'मुक्काम',
            'पो',
            'पोस्ट',
            'पत्ता',
            'पता',
        ], true);
    }

    private function isShortAddressMarkerCandidate(string $token): bool
    {
        return ! preg_match('/^\d+$/u', $token)
            && mb_strlen($token) <= 2;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{profile_snapshot_present: bool, profile_snapshot_sections: list<string>, address_count: ?int, contact_count: ?int, family_section_present: bool}
     */
    private function profileSnapshotMetadata(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [
                'profile_snapshot_present' => false,
                'profile_snapshot_sections' => [],
                'address_count' => null,
                'contact_count' => null,
                'family_section_present' => false,
            ];
        }

        return [
            'profile_snapshot_present' => true,
            'profile_snapshot_sections' => $this->safeTopLevelKeys($snapshot),
            'address_count' => is_array($snapshot['addresses'] ?? null) ? count($snapshot['addresses']) : null,
            'contact_count' => is_array($snapshot['contacts'] ?? null) ? count($snapshot['contacts']) : null,
            'family_section_present' => array_key_exists('family', $snapshot) && is_array($snapshot['family']),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array{source_context_present: bool, source_context_keys: list<string>}
     */
    private function sourceContextMetadata(?array $context): array
    {
        if ($context === null) {
            return [
                'source_context_present' => false,
                'source_context_keys' => [],
            ];
        }

        return [
            'source_context_present' => true,
            'source_context_keys' => $this->safeTopLevelKeys($context),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function safeTopLevelKeys(array $data): array
    {
        return array_values(array_unique(array_map(
            fn (string|int $key): string => $this->safeMetadataKey($key),
            array_keys($data)
        )));
    }

    private function safeMetadataKey(string|int $key): string
    {
        $key = trim((string) $key);

        if (preg_match('/\d{6,}/', $key) === 1 || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
            return 'redacted_key';
        }

        return $this->safeToken($key, 'unknown');
    }

    private function firstParsedFieldValue(array $parsed, string $field): mixed
    {
        if ($field === 'occupation') {
            return $this->parsedOccupationValue($parsed);
        }

        if ($field === 'document_contact_number') {
            return $this->parsedDocumentContactNumbers($parsed);
        }

        foreach (self::FIELD_PATHS[$field] ?? [] as $path) {
            $value = data_get($parsed, $path);
            if (! $this->isBlank($value)) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeForComparison(string $field, mixed $value): ?string
    {
        if ($field === 'document_contact_number') {
            return $this->normalizePhoneSetForComparison($value);
        }

        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $lower = mb_strtolower($text);
        if (in_array($lower, self::PLACEHOLDER_VALUES, true)) {
            return null;
        }

        $text = str_replace(["\u{00A0}", "\u{200B}"], ' ', $text);
        $text = preg_replace('/[“”‘’]/u', "'", $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($field === 'primary_contact_number') {
            return $this->normalizeSinglePhoneForComparison($text);
        }

        if ($field === 'date_of_birth') {
            if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $matches)) {
                return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
            }
            if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $text, $matches)) {
                return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
            }
        }

        if ($field === 'height') {
            return $this->normalizeHeightForComparison($text);
        }

        if ($field === 'occupation') {
            return $this->normalizeOccupationForComparison($text);
        }

        if ($field === 'education') {
            return $this->normalizeEducationForComparison($text);
        }

        if ($field === 'address') {
            return $this->normalizeAddressForComparison($text);
        }

        if ($field === 'religion') {
            return $this->normalizeReligionForComparison($text);
        }

        if ($field === 'caste') {
            return $this->normalizeCasteForComparison($text);
        }

        if ($field === 'sub_caste') {
            return $this->normalizeSubCasteForComparison($text);
        }

        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s.]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private function normalizeAddressForComparison(string $text): ?string
    {
        $text = \App\Services\Ocr\OcrNormalize::normalizeDigits($text);
        $text = str_replace(["\u{00A0}", "\u{200B}", '“', '”', '‘', '’'], [' ', ' ', "'", "'", "'", "'"], $text);
        $text = preg_replace('/^\s*(?:सध्याचा\s+पत्ता|वर्तमान\s+पत्ता|संपर्क\s+पत्ता|घरचा\s+पत्ता|मूळगाव|मूळ\s+गाव|जन्म\s+ठिकाण|जन्मस्थळ|पत्ता|पता|current\s+address|contact\s+address|residence\s+address|native\s+place|birth\s+place|address)\s*(?:[:\-：>\/]|[8]|\s)+/ui', '', $text) ?? $text;
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private function normalizeEducationForComparison(string $text): ?string
    {
        $text = \App\Services\Ocr\OcrNormalize::normalizeDigits($text);
        $text = str_replace(["\u{00A0}", "\u{200B}", '“', '”', '‘', '’'], [' ', ' ', "'", "'", "'", "'"], $text);
        $text = preg_replace('/^\s*(?:शैक्षणिक\s+पात्रता|Educational\s+Qualification|Qualification|Education|शिक्षण)\s*(?:[:\-：>\/]|[८8]|\s)+/ui', '', $text) ?? $text;
        $replacements = [
            '/\bB\s*\.?\s*Tech\b/ui' => 'BTech',
            '/\bM\s*\.?\s*Tech\b/ui' => 'MTech',
            '/\bB\s*\.?\s*Com\b/ui' => 'BCom',
            '/\bM\s*\.?\s*Com\b/ui' => 'MCom',
            '/\bB\s*\.?\s*Sc\b/ui' => 'BSc',
            '/\bM\s*\.?\s*Sc\b/ui' => 'MSc',
            '/\bB\s*\.?\s*E\b/ui' => 'BE',
            '/\bB\s*\.?\s*A\b/ui' => 'BA',
            '/\bM\s*\.?\s*A\b/ui' => 'MA',
            '/\bL\s*\.?\s*L\s*\.?\s*B\b/ui' => 'LLB',
            '/\bM\s*\.?\s*C\s*\.?\s*A\b/ui' => 'MCA',
            '/\bB\s*\.?\s*C\s*\.?\s*S\b/ui' => 'BCS',
            '/\bM\s*\.?\s*C\s*\.?\s*S\b/ui' => 'MCS',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private function normalizeReligionForComparison(string $text): ?string
    {
        $text = \App\Services\Ocr\OcrNormalize::normalizeDigits($text);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;

        if (preg_match('/(?:^|[^\p{L}])Hindu(?:$|[^\p{L}])|हिंद[ुू]/ui', $text)) {
            return 'हिंदू';
        }
        if (preg_match('/(?:^|[^\p{L}])Muslim(?:$|[^\p{L}])|मुस्लिम/ui', $text)) {
            return 'muslim';
        }
        if (preg_match('/(?:^|[^\p{L}])Jain(?:$|[^\p{L}])|जैन/ui', $text)) {
            return 'jain';
        }

        return null;
    }

    private function normalizeCasteForComparison(string $text): ?string
    {
        $text = \App\Services\Ocr\OcrNormalize::normalizeDigits($text);
        if (preg_match('/(?:मराठा|Maratha)/ui', $text)) {
            return 'मराठा';
        }

        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private function normalizeSubCasteForComparison(string $text): ?string
    {
        $text = \App\Services\Ocr\OcrNormalize::normalizeDigits($text);
        $text = str_replace(["\u{00A0}", "\u{200B}", '“', '”', '‘', '’'], [' ', ' ', "'", "'", "'", "'"], $text);
        $text = preg_replace('/(?:उपजात|पोटजात|Sub\s*-?\s*caste|Subcaste)\s*(?:[:\-：]|\s)+/ui', ' ', $text) ?? $text;
        $text = preg_replace('/(?:हिंद[ुू]|Hindu|मराठा|Maratha)/ui', ' ', $text) ?? $text;
        $text = preg_replace('/(?:क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कूळी|कळी)/u', 'कुळी', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if (preg_match('/(?<!\d)(\d{1,3})\s*(?:कुळी|Kuli)(?![\p{L}\p{N}])/ui', $text, $matches)) {
            $count = (int) $matches[1];
            if ($count <= 0 || $count > 200) {
                return null;
            }

            return $count.' कुळी';
        }

        return null;
    }

    private function parsedOccupationValue(array $parsed): mixed
    {
        $titlePaths = [
            'core.occupation_title',
            'core.occupation',
            'core.profession',
            'career_history.0.occupation_title',
            'career_history.0.designation',
            'career_history.0.job_title',
            'career_history.0.role',
        ];

        $generic = null;
        foreach ($titlePaths as $path) {
            $value = data_get($parsed, $path);
            if ($this->isBlank($value)) {
                continue;
            }
            $value = trim((string) $value);
            if (! $this->isGenericOccupationToken($value)) {
                return $value;
            }
            $generic ??= $value;
        }

        foreach ([
            'core.company_name',
            'career_history.0.company_name',
            'career_history.0.employer',
            'career_history.0.company',
        ] as $path) {
            $value = data_get($parsed, $path);
            if (! $this->isBlank($value)) {
                return $value;
            }
        }

        return $generic;
    }

    private function isGenericOccupationToken(string $value): bool
    {
        $normalized = $this->normalizeOccupationForComparison($value);

        return in_array($normalized, ['नोकरी', 'व्यवसाय', 'occupation', 'profession', 'job', 'work'], true);
    }

    private function normalizeOccupationForComparison(string $text): ?string
    {
        $text = \App\Services\Ocr\OcrNormalize::normalizeDigits($text);
        $text = str_replace(["\u{00A0}", "\u{200B}", '“', '”', '‘', '’'], [' ', ' ', "'", "'", "'", "'"], $text);
        $text = preg_replace('/^\s*(?:नोकरी\s*\/\s*व्यवसाय|नोकटी\s*\/\s*व्यवसाय|स्वतःचा\s+व्यवसाय|स्वत:चा\s+व्यवसाय|व्यवसाय|नोकरी|Occupation|Profession|Job|Working\s+as|Designation|Work)\s*(?:[:\-：>\/]|\s)+/ui', '', $text) ?? $text;
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function parsedDocumentContactNumbers(array $parsed): array
    {
        $coreNumbers = $this->collectPhoneNumbersForComparison(data_get($parsed, 'core.document_contact_numbers'))
            ?: $this->collectPhoneNumbersForComparison(data_get($parsed, 'core.document_contact_number'));
        if ($coreNumbers !== []) {
            return $coreNumbers;
        }

        $contacts = data_get($parsed, 'contacts');
        if (! is_array($contacts)) {
            return [];
        }

        $document = [];
        $fallback = [];
        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $numbers = $this->collectPhoneNumbersForComparison([
                $contact['phone_number'] ?? null,
                $contact['mobile'] ?? null,
                $contact['mobile_number'] ?? null,
                $contact['phone'] ?? null,
                $contact['number'] ?? null,
            ]);
            if ($numbers === []) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($contact['type'] ?? '')));
            $label = mb_strtolower(trim((string) ($contact['label'] ?? '')));
            $isPrimary = (bool) ($contact['is_primary'] ?? false);
            if ($type === 'document_contact' || $label === 'document') {
                array_push($document, ...$numbers);
            } elseif (! $isPrimary) {
                array_push($fallback, ...$numbers);
            }
        }

        return $this->uniqueSortedPhones($document !== [] ? $document : $fallback);
    }

    private function normalizeSinglePhoneForComparison(string $text): ?string
    {
        $phones = $this->collectPhoneNumbersForComparison($text);

        return count($phones) === 1 ? $phones[0] : null;
    }

    private function normalizePhoneSetForComparison(mixed $value): ?string
    {
        $phones = $this->collectPhoneNumbersForComparison($value);

        return $phones !== [] ? implode('|', $phones) : null;
    }

    /**
     * @return list<string>
     */
    private function collectPhoneNumbersForComparison(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $phones = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                array_push($phones, ...$this->collectPhoneNumbersForComparison($item));
            }

            return $this->uniqueSortedPhones($phones);
        }

        if (is_object($value)) {
            return [];
        }

        $text = \App\Services\Ocr\OcrNormalize::normalizeDigits((string) $value);
        if (preg_match_all('/(?<!\d)(?:\+?\s*91[\s.\-]*)?([6-9](?:[\s.\-]*\d){9})(?!\d)/u', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                $digits = preg_replace('/\D/u', '', (string) $candidate) ?? '';
                if (preg_match('/^[6-9]\d{9}$/', $digits)) {
                    $phones[] = $digits;
                }
            }
        }

        return $this->uniqueSortedPhones($phones);
    }

    /**
     * @param  list<string>  $phones
     * @return list<string>
     */
    private function uniqueSortedPhones(array $phones): array
    {
        $phones = array_values(array_unique(array_filter(
            $phones,
            static fn (string $phone): bool => (bool) preg_match('/^[6-9]\d{9}$/', $phone)
        )));
        sort($phones, SORT_STRING);

        return $phones;
    }

    private function normalizeHeightForComparison(string $text): ?string
    {
        $height = trim(str_replace(['centimeters', 'centimetres'], 'cm', mb_strtolower($text)));
        if ($height === '') {
            return null;
        }

        if (is_numeric($height)) {
            $key = $this->heightCmComparisonKey((float) $height);
            if ($key !== null) {
                return $key;
            }
        }

        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*cm$/i', $height, $m)) {
            $key = $this->heightCmComparisonKey((float) $m[1]);
            if ($key !== null) {
                return $key;
            }
        }

        $height = \App\Services\Ocr\OcrNormalize::normalizeDigits($height);
        $normalized = \App\Services\Ocr\OcrNormalize::normalizeHeight($height);
        $sources = [];
        foreach ([$normalized, $height] as $source) {
            if (is_string($source) && trim($source) !== '') {
                $sources[] = trim($source);
            }
        }

        foreach (array_values(array_unique($sources)) as $source) {
            if (preg_match('/([3-7])\s*[\'’′]\s*([0-9]{1,2})\s*(?:"|”|″)?/u', $source, $m)) {
                $key = $this->heightFeetInchesComparisonKey((int) $m[1], (int) $m[2]);
                if ($key !== null) {
                    return $key;
                }
            }

            if (preg_match('/([3-7])\s*(?:फूट|फुट|feet|foot|ft)\.?\s*([0-9]{1,2})\s*(?:इंच|inches|inch|in)?/ui', $source, $m)) {
                $key = $this->heightFeetInchesComparisonKey((int) $m[1], (int) $m[2]);
                if ($key !== null) {
                    return $key;
                }
            }

            if (preg_match('/^\s*(?:height|उंची|ऊंची)?\s*[:\-]?\s*([3-7])\s*[\.\-]\s*([0-9]{1,2})\s*$/ui', $source, $m)) {
                $key = $this->heightFeetInchesComparisonKey((int) $m[1], (int) $m[2]);
                if ($key !== null) {
                    return $key;
                }
            }
        }

        $height = preg_replace('/\s+/u', '', $height) ?? $height;

        return trim($height) !== '' ? $height : null;
    }

    private function heightCmComparisonKey(float $cm): ?string
    {
        if ($cm < 90 || $cm > 230) {
            return null;
        }

        $totalInches = (int) round($cm / 2.54);
        $feet = intdiv($totalInches, 12);
        $inches = $totalInches % 12;

        return $this->heightFeetInchesComparisonKey($feet, $inches);
    }

    private function heightFeetInchesComparisonKey(int $feet, int $inches): ?string
    {
        if ($feet < 3 || $feet > 7 || $inches < 0 || $inches > 11) {
            return null;
        }

        return $feet.'ft'.$inches.'in';
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, array{total_expected: int, exact_match_count: int, mismatch_count: int, missing_count: int}>
     */
    private function emptyFieldStats(array $fields): array
    {
        $stats = [];
        foreach ($fields as $field) {
            $stats[$field] = [
                'total_expected' => 0,
                'exact_match_count' => 0,
                'mismatch_count' => 0,
                'missing_count' => 0,
            ];
        }

        return $stats;
    }

    /**
     * @param  array<string, array{total_expected: int, exact_match_count: int, mismatch_count: int, missing_count: int}>  $target
     * @param  array<string, array{total_expected: int, exact_match_count: int, mismatch_count: int, missing_count: int}>  $source
     */
    private function mergeFieldStats(array &$target, array $source): void
    {
        foreach ($source as $field => $stats) {
            foreach ($stats as $key => $value) {
                $target[$field][$key] = ($target[$field][$key] ?? 0) + (int) $value;
            }
        }
    }

    /**
     * @param  array<string, array{case_count: int, exact_match_count: int, field_expected_count: int}>  $layoutStats
     * @param  array<string, mixed>  $row
     */
    private function mergeLayoutStats(array &$layoutStats, string $layoutType, array $row): void
    {
        $layout = $this->safeToken($layoutType, 'unknown');
        if (! isset($layoutStats[$layout])) {
            $layoutStats[$layout] = [
                'case_count' => 0,
                'exact_match_count' => 0,
                'field_expected_count' => 0,
            ];
        }

        $layoutStats[$layout]['case_count']++;
        $layoutStats[$layout]['exact_match_count'] += (int) ($row['exact_match_count'] ?? 0);
        $layoutStats[$layout]['field_expected_count'] += (int) ($row['fields_expected_count'] ?? 0);
    }

    /**
     * @param  array<string, array{total_expected: int, exact_match_count: int, mismatch_count: int, missing_count: int}>  $fieldStats
     * @return list<array<string, mixed>>
     */
    private function fieldAccuracy(array $fieldStats): array
    {
        $rows = [];
        foreach ($fieldStats as $field => $stats) {
            $total = (int) $stats['total_expected'];
            $exact = (int) $stats['exact_match_count'];
            $rows[] = [
                'field' => $field,
                'total_expected' => $total,
                'exact_match_count' => $exact,
                'mismatch_count' => (int) $stats['mismatch_count'],
                'missing_count' => (int) $stats['missing_count'],
                'accuracy_percent' => $this->percent($exact, $total),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{case_count: int, exact_match_count: int, field_expected_count: int}>  $layoutStats
     * @return list<array<string, mixed>>
     */
    private function layoutAccuracy(array $layoutStats): array
    {
        $rows = [];
        ksort($layoutStats);
        foreach ($layoutStats as $layout => $stats) {
            $expected = (int) $stats['field_expected_count'];
            $exact = (int) $stats['exact_match_count'];
            $rows[] = [
                'layout_type' => $layout,
                'case_count' => (int) $stats['case_count'],
                'exact_match_count' => $exact,
                'field_expected_count' => $expected,
                'accuracy_percent' => $this->percent($exact, $expected),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $fieldAccuracy
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(
        int $loadedTotal,
        int $validCases,
        int $invalidCases,
        array $fieldAccuracy,
        array $rows,
        ?float $failUnder,
    ): array {
        $totalExpected = 0;
        $exact = 0;
        $mismatch = 0;
        $missing = 0;
        foreach ($fieldAccuracy as $row) {
            $totalExpected += (int) $row['total_expected'];
            $exact += (int) $row['exact_match_count'];
            $mismatch += (int) $row['mismatch_count'];
            $missing += (int) $row['missing_count'];
        }

        $accuracy = $this->percent($exact, $totalExpected);
        $status = 'pass';
        if ($invalidCases > 0 || $validCases === 0) {
            $status = 'invalid_dataset';
        } elseif ($failUnder !== null && $accuracy < $failUnder) {
            $status = 'fail_under_threshold';
        } elseif ($mismatch > 0 || $missing > 0) {
            $status = 'pass';
        }

        return [
            'total_cases' => $loadedTotal,
            'valid_cases' => $validCases,
            'invalid_cases' => $invalidCases,
            'total_expected_fields' => $totalExpected,
            'exact_match_count' => $exact,
            'mismatch_count' => $mismatch,
            'missing_count' => $missing,
            'overall_accuracy_percent' => $accuracy,
            'regression_status' => $status,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fieldAccuracy
     * @param  array<string, float>  $fieldThresholds
     * @return array{
     *     threshold_status: string,
     *     overall_threshold: array<string, mixed>|null,
     *     field_thresholds: list<array<string, mixed>>,
     *     threshold_failures: list<array<string, mixed>>
     * }
     */
    private function thresholdReport(array $summary, array $fieldAccuracy, ?float $failUnder, array $fieldThresholds): array
    {
        $failures = [];
        $overallThreshold = null;

        if ($failUnder !== null) {
            $overallAccuracy = (float) $summary['overall_accuracy_percent'];
            $status = $overallAccuracy >= $failUnder ? 'pass' : 'fail';
            $overallThreshold = [
                'threshold' => $failUnder,
                'accuracy_percent' => $overallAccuracy,
                'status' => $status,
            ];

            if ($status === 'fail') {
                $failures[] = [
                    'scope' => 'overall',
                    'field' => null,
                    'threshold' => $failUnder,
                    'accuracy_percent' => $overallAccuracy,
                    'reason' => 'below_threshold',
                ];
            }
        }

        $accuracyByField = [];
        foreach ($fieldAccuracy as $row) {
            $accuracyByField[(string) $row['field']] = (float) $row['accuracy_percent'];
        }

        $fieldThresholdRows = [];
        foreach ($fieldThresholds as $field => $threshold) {
            if (! array_key_exists($field, $accuracyByField)) {
                $fieldThresholdRows[] = [
                    'field' => $field,
                    'threshold' => $threshold,
                    'accuracy_percent' => null,
                    'status' => 'fail',
                    'reason' => 'field_not_evaluated',
                ];
                $failures[] = [
                    'scope' => 'field',
                    'field' => $field,
                    'threshold' => $threshold,
                    'accuracy_percent' => null,
                    'reason' => 'field_not_evaluated',
                ];

                continue;
            }

            $accuracy = $accuracyByField[$field];
            $status = $accuracy >= $threshold ? 'pass' : 'fail';
            $fieldThresholdRows[] = [
                'field' => $field,
                'threshold' => $threshold,
                'accuracy_percent' => $accuracy,
                'status' => $status,
                'reason' => $status === 'pass' ? null : 'below_threshold',
            ];

            if ($status === 'fail') {
                $failures[] = [
                    'scope' => 'field',
                    'field' => $field,
                    'threshold' => $threshold,
                    'accuracy_percent' => $accuracy,
                    'reason' => 'below_threshold',
                ];
            }
        }

        return [
            'threshold_status' => $failures === [] ? 'pass' : 'fail',
            'overall_threshold' => $overallThreshold,
            'field_thresholds' => $fieldThresholdRows,
            'threshold_failures' => $failures,
        ];
    }

    /**
     * @param  array<string, float>  $fieldThresholds
     * @return list<string>
     */
    private function fieldThresholdFilterDisplay(array $fieldThresholds): array
    {
        $values = [];
        foreach ($fieldThresholds as $field => $threshold) {
            $values[] = $field.':'.$threshold;
        }

        return $values;
    }

    private function renderReport(array $report): void
    {
        $summary = $report['summary'];
        $this->info('Intake OCR Regression');
        $this->line('Dataset: '.$report['filters']['dataset']);
        $this->line('Total cases: '.$summary['total_cases']);
        $this->line('Valid cases: '.$summary['valid_cases']);
        $this->line('Invalid cases: '.$summary['invalid_cases']);
        $this->line('Total expected fields: '.$summary['total_expected_fields']);
        $this->line('Exact matches: '.$summary['exact_match_count']);
        $this->line('Mismatches: '.$summary['mismatch_count']);
        $this->line('Missing: '.$summary['missing_count']);
        $this->line('Overall accuracy: '.$summary['overall_accuracy_percent'].'%');
        $this->line('Regression status: '.$summary['regression_status']);
        $this->line('Threshold status: '.$report['threshold_status']);

        $this->renderThresholdSummary($report);

        $this->newLine();
        $this->line('Field accuracy');
        $this->table(
            ['Field', 'Expected', 'Exact', 'Mismatch', 'Missing', 'Accuracy %'],
            collect($report['field_accuracy'])->map(fn (array $row): array => [
                $row['field'],
                $row['total_expected'],
                $row['exact_match_count'],
                $row['mismatch_count'],
                $row['missing_count'],
                $row['accuracy_percent'],
            ])->all()
        );

        $this->line('Layout accuracy');
        $this->table(
            ['Layout', 'Cases', 'Expected fields', 'Exact', 'Accuracy %'],
            collect($report['layout_accuracy'])->map(fn (array $row): array => [
                $row['layout_type'],
                $row['case_count'],
                $row['field_expected_count'],
                $row['exact_match_count'],
                $row['accuracy_percent'],
            ])->all()
        );

        if ($report['schema_errors'] !== []) {
            $this->line('Schema errors');
            $this->table(
                ['Line', 'Case ID', 'Errors'],
                collect($report['schema_errors'])->map(fn (array $row): array => [
                    $row['line'] ?? '',
                    $row['case_id'] ?? '',
                    implode(', ', $row['error_codes'] ?? []),
                ])->all()
            );
        }

        $this->line('Rows');
        $this->table(
            ['Case ID', 'Layout', 'Lang', 'Expected', 'Exact', 'Mismatch fields', 'Missing fields', 'Snapshot', 'Sections', 'Addresses', 'Contacts', 'Family', 'Source context', 'Source keys', 'Status'],
            collect($report['rows'])->map(fn (array $row): array => [
                $row['case_id'],
                $row['layout_type'],
                $row['language'],
                $row['fields_expected_count'],
                $row['exact_match_count'],
                implode(', ', $row['mismatch_fields']),
                implode(', ', $row['missing_fields']),
                ($row['profile_snapshot_present'] ?? false) ? 'yes' : 'no',
                implode(', ', $row['profile_snapshot_sections'] ?? []),
                $row['address_count'] ?? '',
                $row['contact_count'] ?? '',
                ($row['family_section_present'] ?? false) ? 'yes' : 'no',
                ($row['source_context_present'] ?? false) ? 'yes' : 'no',
                implode(', ', $row['source_context_keys'] ?? []),
                $row['status'],
            ])->all()
        );
    }

    private function renderThresholdSummary(array $report): void
    {
        if (($report['overall_threshold'] ?? null) === null && ($report['field_thresholds'] ?? []) === []) {
            return;
        }

        $rows = [];
        if (is_array($report['overall_threshold'] ?? null)) {
            $overall = $report['overall_threshold'];
            $rows[] = [
                'overall',
                '',
                $overall['threshold'],
                $overall['accuracy_percent'],
                $overall['status'],
                $overall['status'] === 'pass' ? '' : 'below_threshold',
            ];
        }

        foreach (($report['field_thresholds'] ?? []) as $row) {
            $rows[] = [
                'field',
                $row['field'],
                $row['threshold'],
                $row['accuracy_percent'] ?? '',
                $row['status'],
                $row['reason'] ?? '',
            ];
        }

        $this->newLine();
        $this->line('Threshold summary');
        $this->table(
            ['Scope', 'Field', 'Threshold %', 'Accuracy %', 'Status', 'Reason'],
            $rows
        );
    }

    private function safeScalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private function safeToken(string $value, string $fallback): string
    {
        $value = Str::of($value)->trim()->lower()->replaceMatches('/[^a-z0-9_\-]+/', '_')->trim('_')->toString();

        return $value !== '' ? $value : $fallback;
    }

    private function isBlank(mixed $value): bool
    {
        return $this->normalizeForComparison('text', $value) === null;
    }

    private function percent(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function safeDatasetDisplay(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $storage = str_replace('\\', '/', storage_path('app'));
        if (str_starts_with($normalizedPath, $storage.'/')) {
            return 'storage/app/'.substr($normalizedPath, strlen($storage) + 1);
        }

        $base = str_replace('\\', '/', base_path());
        if (str_starts_with($normalizedPath, $base.'/tests/Fixtures/')) {
            return 'tests/Fixtures/'.substr($normalizedPath, strlen($base.'/tests/Fixtures/'));
        }
        if (str_starts_with($normalizedPath, $base.'/tests/fixtures/')) {
            return 'tests/fixtures/'.substr($normalizedPath, strlen($base.'/tests/fixtures/'));
        }

        return basename($path);
    }

    private function shouldRedactCaseIds(string $path): bool
    {
        return str_starts_with(
            $this->safeDatasetDisplay($path),
            'storage/app/intake-golden-datasets/'
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function redactRowCaseIds(array $rows): array
    {
        return array_values(array_map(function (array $row, int $index): array {
            $row['case_id'] = sprintf('case_%03d', $index + 1);

            return $row;
        }, $rows, array_keys($rows)));
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @return list<array<string, mixed>>
     */
    private function redactSchemaErrorCaseIds(array $errors): array
    {
        return array_values(array_map(function (array $error, int $index): array {
            $line = $error['line'] ?? null;
            $error['case_id'] = is_int($line)
                ? sprintf('line_%03d', $line)
                : sprintf('schema_error_%03d', $index + 1);

            return $error;
        }, $errors, array_keys($errors)));
    }
}
