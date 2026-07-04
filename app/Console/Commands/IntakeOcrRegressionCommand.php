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
        'primary_contact_number' => ['core.primary_contact_number', 'core.phone_number', 'core.mobile_number', 'core.mobile', 'candidate.primary_contact_number', 'contacts.0.phone_number', 'contacts.0.mobile', 'contacts.0.mobile_number', 'contacts.0.phone', 'contacts.0.number'],
        'document_contact_number' => ['contacts.0.phone_number', 'contacts.0.mobile', 'contacts.0.mobile_number', 'contacts.0.phone', 'contacts.0.number', 'core.primary_contact_number', 'core.phone_number', 'core.mobile_number', 'core.mobile', 'candidate.primary_contact_number'],
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
        {--fail-under= : Optional minimum overall accuracy percentage}';

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

        if ($field === false || $failUnder === false) {
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
        $summary = $this->summary(
            loadedTotal: $loaded['total_loaded'],
            validCases: $validCases,
            invalidCases: count($loaded['schema_errors']),
            fieldAccuracy: $fieldAccuracy,
            rows: $rows,
            failUnder: $failUnder
        );

        $report = [
            'success' => $summary['regression_status'] === 'pass',
            'filters' => [
                'dataset' => $this->safeDatasetDisplay($path),
                'field' => $field,
                'limit' => $limit,
                'fail_under' => $failUnder,
            ],
            'summary' => $summary,
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
            ],
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
            ],
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

            if ($actual === $expected) {
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

        if ($field === 'primary_contact_number' || $field === 'document_contact_number') {
            $digits = preg_replace('/\D+/', '', $text) ?? '';
            if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
                $digits = substr($digits, -10);
            }

            return $digits !== '' ? $digits : null;
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
            $height = mb_strtolower($text);
            $height = str_replace(['centimeters', 'centimetres'], 'cm', $height);
            $height = preg_replace('/\s+/u', '', $height) ?? $height;

            return trim($height) !== '' ? $height : null;
        }

        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s.]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
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
}
