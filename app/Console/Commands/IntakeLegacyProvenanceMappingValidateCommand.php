<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class IntakeLegacyProvenanceMappingValidateCommand extends Command
{
    private const COLUMNS = [
        'intake_id',
        'reviewed_snapshot_present',
        'reviewed_at_present',
        'current_actor_type',
        'current_actor_id_present',
        'current_surface',
        'suggested_actor_type',
        'suggested_actor_id_present',
        'suggested_surface',
        'evidence_source',
        'confidence',
        'can_backfill_safely',
        'reason',
        'manual_actor_type',
        'manual_actor_id',
        'manual_surface',
        'manual_notes',
        'reviewer_decision',
    ];

    private const MANUAL_ACTORS = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
    ];

    private const MANUAL_SURFACES = [
        BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
        BiodataIntakeOcrAttempt::SURFACE_API,
    ];

    private const REVIEWER_DECISIONS = [
        'approve_manual_mapping',
        'skip',
        'needs_more_evidence',
    ];

    protected $signature = 'intake:legacy-provenance-mapping-validate
        {--file= : CSV file under storage/app to validate}
        {--json : Print the validation report as JSON}
        {--strict : Reject confidence=none approvals}
        {--fail-on-risk : Exit non-zero when invalid or risky rows are found}';

    protected $description = 'Read-only validation of a manually filled legacy intake provenance mapping CSV.';

    public function handle(): int
    {
        $file = $this->stringOption('file');
        if ($file === null) {
            $this->error('Missing required --file option.');

            return self::FAILURE;
        }

        $path = $this->resolveInputPath($file);
        if ($path === false || ! File::exists($path)) {
            $this->error('CSV file not found under storage/app.');

            return self::FAILURE;
        }

        $csv = $this->readCsv($path);
        $strict = (bool) $this->option('strict');
        $headerErrors = $this->headerErrors($csv['columns']);
        $rows = collect($csv['rows'])
            ->map(fn (array $row): array => $this->validateRow($row, $strict))
            ->values();
        $summary = $this->summary($rows, $headerErrors);
        $report = [
            'success' => true,
            'filters' => [
                'file' => $this->safeFileDisplay($path),
                'strict' => $strict,
                'fail_on_risk' => (bool) $this->option('fail-on-risk'),
            ],
            'allowed_values' => [
                'manual_actor_type' => self::MANUAL_ACTORS,
                'manual_surface' => self::MANUAL_SURFACES,
                'reviewer_decision' => self::REVIEWER_DECISIONS,
            ],
            'expected_columns' => self::COLUMNS,
            'summary' => $summary,
            'rows' => $rows->all(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderReport($report);
        }

        if ((bool) $this->option('fail-on-risk') && ($summary['validation_status'] ?? 'fail') === 'fail') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, string>>}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['columns' => [], 'rows' => []];
        }

        $columns = [];
        $rows = [];

        while (($record = fgetcsv($handle)) !== false) {
            if ($record === [null] || $record === [] || $this->isEmptyCsvRecord($record)) {
                continue;
            }

            $first = trim((string) ($record[0] ?? ''));
            if (str_starts_with($first, '#')) {
                continue;
            }

            if ($columns === []) {
                $columns = array_map(fn (mixed $column): string => $this->normalizeHeader((string) $column), $record);
                continue;
            }

            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = trim((string) ($record[$index] ?? ''));
            }
            $rows[] = $row;
        }

        fclose($handle);

        return [
            'columns' => array_values($columns),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, string>  $csvRow
     * @return array<string, mixed>
     */
    private function validateRow(array $csvRow, bool $strict): array
    {
        $riskCodes = [];
        $intakeId = $this->nullableInt($csvRow['intake_id'] ?? null);
        $confidence = $this->confidenceValue($csvRow['confidence'] ?? null);
        $decision = $this->safeToken($csvRow['reviewer_decision'] ?? null, '');
        $manualActor = $this->safeToken($csvRow['manual_actor_type'] ?? null, '');
        $manualActorId = $this->nullableInt($csvRow['manual_actor_id'] ?? null);
        $manualSurface = $this->safeToken($csvRow['manual_surface'] ?? null, '');
        $manualNotesPresent = trim((string) ($csvRow['manual_notes'] ?? '')) !== '';
        $canBackfillSafely = $this->booleanCsv($csvRow['can_backfill_safely'] ?? null);

        $intake = $intakeId !== null
            ? BiodataIntake::query()
                ->select([
                    'id',
                    'approval_snapshot_json',
                    'reviewed_by_user_id',
                    'review_actor_type',
                    'review_surface',
                    'raw_ocr_text',
                    'parsed_json',
                    'parse_status',
                    'quality_summary_json',
                    'failure_codes_json',
                    'field_confidence_json',
                    'routing_recommendation_json',
                ])
                ->find($intakeId)
            : null;

        if ($intakeId === null) {
            $riskCodes[] = 'invalid_intake_id';
        }

        if ($intake === null) {
            $riskCodes[] = 'intake_not_found';
        }

        $dbMatches = $intake !== null && $this->hasReviewedSnapshot($intake);
        if ($intake !== null && ! $this->hasReviewedSnapshot($intake)) {
            $riskCodes[] = 'db_reviewed_snapshot_missing';
        }

        $stale = $intake !== null && $this->hasCompleteProvenance($intake);
        if ($stale) {
            $riskCodes[] = 'stale_db_provenance_present';
        }

        if (! in_array($decision, self::REVIEWER_DECISIONS, true)) {
            $riskCodes[] = 'invalid_reviewer_decision';
        }

        if ($decision === 'approve_manual_mapping') {
            if (! in_array($manualActor, self::MANUAL_ACTORS, true)) {
                $riskCodes[] = 'invalid_manual_actor_type';
            }

            if ($manualActorId === null) {
                $riskCodes[] = 'manual_actor_id_missing';
            } elseif (! $this->userExists($manualActorId)) {
                $riskCodes[] = 'manual_actor_id_not_found';
            }

            if (! in_array($manualSurface, self::MANUAL_SURFACES, true)) {
                $riskCodes[] = 'invalid_manual_surface';
            }

            if (in_array($confidence, ['medium', 'low', 'none'], true) && ! $manualNotesPresent) {
                $riskCodes[] = 'manual_notes_required_for_non_high_confidence';
            }

            if ($strict && $confidence === 'none') {
                $riskCodes[] = 'none_confidence_not_approvable_in_strict_mode';
            }

            if ($canBackfillSafely === false && ! $manualNotesPresent) {
                $riskCodes[] = 'unsafe_template_row_requires_manual_notes';
            }
        }

        $riskCodes = array_values(array_unique($riskCodes));
        $validationStatus = $this->validationStatus($riskCodes, $stale);
        $futureApplyCandidate = $decision === 'approve_manual_mapping'
            && $dbMatches
            && ! $stale
            && $riskCodes === [];

        return [
            'intake_id' => $intakeId ?? 0,
            'db_match' => $dbMatches,
            'stale_row' => $stale,
            'csv_confidence' => $confidence,
            'reviewer_decision' => $decision !== '' ? $decision : 'missing',
            'manual_actor_type' => in_array($manualActor, self::MANUAL_ACTORS, true) ? $manualActor : 'invalid_or_missing',
            'manual_actor_id_present' => $this->yesNo($manualActorId !== null),
            'manual_surface' => in_array($manualSurface, self::MANUAL_SURFACES, true) ? $manualSurface : 'invalid_or_missing',
            'validation_status' => $validationStatus,
            'risk_codes' => $riskCodes,
            'future_apply_candidate' => $futureApplyCandidate,
            'recommendation' => $this->rowRecommendation($decision, $validationStatus, $futureApplyCandidate),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $headerErrors
     * @return array<string, mixed>
     */
    private function summary(Collection $rows, array $headerErrors): array
    {
        $invalidRows = $rows->filter(fn (array $row): bool => ($row['validation_status'] ?? '') === 'fail')->count();
        $riskyRows = $rows->filter(fn (array $row): bool => $this->arrayValue($row['risk_codes'] ?? []) !== [])->count();
        $futureApplyCandidateCount = $rows->where('future_apply_candidate', true)->count();
        $validationFails = $headerErrors !== [] || $invalidRows > 0 || $riskyRows > 0;

        return [
            'total_csv_rows' => $rows->count(),
            'rows_matching_db' => $rows->where('db_match', true)->count(),
            'stale_rows' => $rows->where('stale_row', true)->count(),
            'approved_manual_mappings' => $rows->where('reviewer_decision', 'approve_manual_mapping')->count(),
            'skipped_rows' => $rows->where('reviewer_decision', 'skip')->count(),
            'needs_more_evidence_rows' => $rows->where('reviewer_decision', 'needs_more_evidence')->count(),
            'invalid_rows' => $invalidRows,
            'risky_rows' => $riskyRows,
            'future_apply_candidate_count' => $futureApplyCandidateCount,
            'validation_status' => $validationFails ? 'fail' : 'pass',
            'recommendation' => $this->summaryRecommendation($validationFails, $futureApplyCandidateCount),
            'header_errors' => $headerErrors,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);

        $this->table(['Metric', 'Value'], [
            ['Total CSV rows', $summary['total_csv_rows'] ?? 0],
            ['Rows matching DB', $summary['rows_matching_db'] ?? 0],
            ['Stale rows', $summary['stale_rows'] ?? 0],
            ['Approved manual mappings', $summary['approved_manual_mappings'] ?? 0],
            ['Skipped rows', $summary['skipped_rows'] ?? 0],
            ['Needs more evidence rows', $summary['needs_more_evidence_rows'] ?? 0],
            ['Invalid rows', $summary['invalid_rows'] ?? 0],
            ['Risky rows', $summary['risky_rows'] ?? 0],
            ['Future apply candidate count', $summary['future_apply_candidate_count'] ?? 0],
            ['Validation status', $summary['validation_status'] ?? 'fail'],
            ['Recommendation', $summary['recommendation'] ?? 'fix_csv_first'],
            ['Header errors', implode(',', $this->tokenList($summary['header_errors'] ?? [])) ?: '-'],
        ]);

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'CSV confidence',
            'Reviewer decision',
            'Manual actor',
            'Manual actor ID',
            'Manual surface',
            'Validation',
            'Risk codes',
            'Recommendation',
        ], array_map(fn (array $row): array => [
            $row['intake_id'] ?? 0,
            $this->safeToken($row['csv_confidence'] ?? null, 'unknown'),
            $this->safeToken($row['reviewer_decision'] ?? null, 'missing'),
            $this->safeToken($row['manual_actor_type'] ?? null, 'invalid_or_missing'),
            $this->safeToken($row['manual_actor_id_present'] ?? null, 'no'),
            $this->safeToken($row['manual_surface'] ?? null, 'invalid_or_missing'),
            $this->safeToken($row['validation_status'] ?? null, 'fail'),
            implode(',', $this->tokenList($row['risk_codes'] ?? [])) ?: '-',
            $this->safeToken($row['recommendation'] ?? null, 'fix_csv_first'),
        ], $rows));
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function headerErrors(array $columns): array
    {
        if ($columns === []) {
            return ['missing_header'];
        }

        $missing = array_values(array_diff(self::COLUMNS, $columns));
        if ($missing === []) {
            return [];
        }

        return array_map(fn (string $column): string => 'missing_column_'.$column, $missing);
    }

    /**
     * @param  list<string>  $record
     */
    private function isEmptyCsvRecord(array $record): bool
    {
        foreach ($record as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function resolveInputPath(string $input): string|false
    {
        $input = trim($input);
        if ($input === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $input);
        $segments = array_filter(explode('/', $normalized), fn (string $segment): bool => $segment !== '');
        if (in_array('..', $segments, true)) {
            return false;
        }

        $storageRoot = $this->normalizePath(storage_path('app'));
        if (str_starts_with($this->normalizePath($input), $storageRoot.'/')) {
            $path = $input;
        } elseif (str_starts_with($normalized, 'storage/app/')) {
            $path = base_path($normalized);
        } else {
            $path = storage_path('app/'.$normalized);
        }

        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath !== $storageRoot && ! str_starts_with($normalizedPath, $storageRoot.'/')) {
            return false;
        }

        return $path;
    }

    private function hasReviewedSnapshot(BiodataIntake $intake): bool
    {
        return $this->arrayValue($intake->approval_snapshot_json) !== [];
    }

    private function hasCompleteProvenance(BiodataIntake $intake): bool
    {
        return $this->hasReviewedSnapshot($intake)
            && $intake->reviewed_by_user_id !== null
            && in_array($this->actorBucket($intake->review_actor_type), self::MANUAL_ACTORS, true)
            && in_array($this->surfaceBucket($intake->review_surface), self::MANUAL_SURFACES, true);
    }

    private function userExists(int $userId): bool
    {
        return $userId > 0 && \App\Models\User::query()->whereKey($userId)->exists();
    }

    private function validationStatus(array $riskCodes, bool $stale): string
    {
        if ($riskCodes === []) {
            return 'pass';
        }

        if ($stale && $riskCodes === ['stale_db_provenance_present']) {
            return 'warning';
        }

        return 'fail';
    }

    private function rowRecommendation(string $decision, string $status, bool $futureApplyCandidate): string
    {
        if ($status === 'fail') {
            return 'fix_csv_first';
        }

        if ($futureApplyCandidate) {
            return 'ready_for_manual_apply_review';
        }

        if ($decision === 'needs_more_evidence') {
            return 'needs_more_evidence';
        }

        return 'no_apply';
    }

    private function summaryRecommendation(bool $validationFails, int $futureApplyCandidateCount): string
    {
        if ($validationFails) {
            return 'fix_csv_first';
        }

        if ($futureApplyCandidateCount > 0) {
            return 'ready_for_manual_apply_review';
        }

        return 'no_apply';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function confidenceValue(mixed $value): string
    {
        $confidence = $this->safeToken($value, 'none');

        return in_array($confidence, ['high', 'medium', 'low', 'none'], true) ? $confidence : 'none';
    }

    private function booleanCsv(mixed $value): ?bool
    {
        $token = $this->safeToken($value, '');
        if (in_array($token, ['yes', 'true', '1'], true)) {
            return true;
        }
        if (in_array($token, ['no', 'false', '0'], true)) {
            return false;
        }

        return null;
    }

    private function actorBucket(mixed $value): string
    {
        $actor = $this->safeToken($value, 'unknown');
        if ($actor === 'user') {
            return BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER;
        }

        return in_array($actor, [...self::MANUAL_ACTORS, BiodataIntakeOcrAttempt::ACTOR_SYSTEM], true)
            ? $actor
            : 'unknown';
    }

    private function surfaceBucket(mixed $value): string
    {
        $surface = $this->safeToken($value, 'unknown');

        return in_array($surface, [...self::MANUAL_SURFACES, BiodataIntakeOcrAttempt::SURFACE_SERVER], true)
            ? $surface
            : 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private function tokenList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => $this->safeToken($item, ''), $value),
            fn (string $item): bool => $item !== ''
        ));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            ? null
            : (int) $value;
    }

    private function normalizeHeader(string $header): string
    {
        return trim(str_replace("\xEF\xBB\xBF", '', $header));
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function safeFileDisplay(string $path): string
    {
        $normalizedPath = $this->normalizePath($path);
        $storageRoot = $this->normalizePath(storage_path('app'));
        if ($normalizedPath === $storageRoot) {
            return 'storage/app';
        }

        if (str_starts_with($normalizedPath, $storageRoot.'/')) {
            return 'storage/app/'.substr($normalizedPath, strlen($storageRoot) + 1);
        }

        return basename($path);
    }

    private function safeToken(mixed $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }

        $token = Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_.:-]+/', '_')
            ->trim('_')
            ->toString();

        return $token !== '' ? $token : $default;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
