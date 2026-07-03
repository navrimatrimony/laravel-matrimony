<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IntakeLearningReadinessAuditCommand extends Command
{
    private const FIELDS = [
        'full_name',
        'date_of_birth',
        'height',
        'education',
        'occupation',
        'primary_contact_number',
        'address',
        'religion',
        'caste',
    ];

    private const ACTORS = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
    ];

    private const ACTOR_BUCKETS = [
        'admin',
        'profile_user',
        'suchak',
        'system',
        'unknown',
    ];

    private const SURFACE_BUCKETS = [
        'admin_panel',
        'mobile_app',
        'website',
        'api',
        'unknown',
    ];

    protected $signature = 'intake:learning-readiness-audit
        {--limit=500 : Maximum latest intakes to inspect}
        {--json : Print the report as JSON}
        {--actor= : Include only reviewed snapshots from actor: admin, profile_user, suchak}
        {--field= : Audit one field: full_name, date_of_birth, height, education, occupation, primary_contact_number, address, religion, caste}
        {--include-unreviewed : Include intakes without reviewed snapshots}
        {--min-samples=10 : Minimum clean reviewed samples required for candidate-rule readiness}';

    protected $description = 'Read-only audit of intake data readiness for future learning rules.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $minSamples = max(1, (int) $this->option('min-samples'));
        $actor = $this->actorOption();
        $field = $this->fieldOption();

        if ($actor === false || $field === false) {
            return self::FAILURE;
        }

        $includeUnreviewed = (bool) $this->option('include-unreviewed');
        $fields = $field !== null ? [$field] : self::FIELDS;
        $rows = $this->loadIntakes($limit, $includeUnreviewed, $actor)
            ->map(fn (BiodataIntake $intake): ?array => $this->auditRow($intake, $fields, $field !== null))
            ->filter()
            ->values();
        $summary = $this->summary($rows, $fields, $minSamples);

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'actor' => $actor,
                'field' => $field,
                'include_unreviewed' => $includeUnreviewed,
                'min_samples' => $minSamples,
            ],
            'summary' => $summary,
            'rows' => $rows->all(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, BiodataIntake>
     */
    private function loadIntakes(int $limit, bool $includeUnreviewed, ?string $actor): Collection
    {
        $query = BiodataIntake::query()
            ->select([
                'id',
                'matrimony_profile_id',
                'parse_status',
                'approval_snapshot_json',
                'review_actor_type',
                'review_surface',
                'approved_by_user',
                'reviewed_at',
                'quality_summary_json',
                'failure_codes_json',
                'field_confidence_json',
                'routing_recommendation_json',
                'routing_telemetry_json',
                'created_at',
            ])
            ->with(['ocrAttempts' => function ($query): void {
                $query->select([
                    'id',
                    'intake_id',
                    'engine',
                    'status',
                ]);
            }])
            ->latest('id')
            ->limit($limit);

        if (! $includeUnreviewed) {
            $query->whereNotNull('approval_snapshot_json');
        }

        if ($actor !== null) {
            $query->where('review_actor_type', $actor);
        }

        return $query->get();
    }

    /**
     * @param  list<string>  $fields
     */
    private function auditRow(BiodataIntake $intake, array $fields, bool $fieldFiltered): ?array
    {
        $snapshot = $this->arrayValue($intake->approval_snapshot_json);
        $fieldConfidence = $this->arrayValue($intake->field_confidence_json);
        $recommendation = $this->arrayValue($intake->routing_recommendation_json);
        $signals = $this->arrayValue($recommendation['signals'] ?? []);
        $reasonCodes = $this->importantReasonCodes($this->tokenList($recommendation['reason_codes'] ?? []));
        $failureCodes = $this->tokenList($intake->failure_codes_json);
        $routingAction = $this->safeToken($recommendation['recommended_action'] ?? null, 'unknown');

        $snapshotFields = $this->snapshotCoverage($snapshot, $fields);
        $confidenceFields = $this->fieldConfidenceCoverage($fieldConfidence, $fields);
        $lowConfidenceCorrectedFields = array_values(array_intersect(
            $snapshotFields,
            $this->lowConfidenceFields($fieldConfidence, $fields)
        ));

        if ($fieldFiltered && $snapshotFields === [] && $confidenceFields === [] && $lowConfidenceCorrectedFields === []) {
            return null;
        }

        $actor = $this->actorBucket($intake->review_actor_type);
        $surface = $this->surfaceBucket($intake->review_surface);
        $hasReviewedSnapshot = $snapshot !== [];
        $hasOcrAttempts = $intake->ocrAttempts->isNotEmpty();
        $hasSarvamAttempt = $intake->ocrAttempts->contains(
            fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->engine === BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION
        );
        $providerCandidate = $this->isProviderCandidate($routingAction, $signals, $reasonCodes);
        $conflictRisk = $this->hasConflictRisk($routingAction, $reasonCodes, $failureCodes, $providerCandidate);
        $blockers = $this->rowBlockers($hasReviewedSnapshot, $actor, $snapshotFields, $providerCandidate, $conflictRisk);
        $learningCandidate = $hasReviewedSnapshot
            && in_array($actor, self::ACTORS, true)
            && $snapshotFields !== []
            && ! $conflictRisk;

        return [
            'intake_id' => (int) $intake->id,
            'has_reviewed_snapshot' => $hasReviewedSnapshot,
            'review_actor_type' => $actor,
            'review_surface' => $surface,
            'has_ocr_attempts' => $hasOcrAttempts,
            'has_sarvam_attempt' => $hasSarvamAttempt,
            'routing_action' => $routingAction,
            'reason_codes' => $reasonCodes,
            'learning_candidate' => $learningCandidate,
            'blocker_summary' => $blockers,
            'field_confidence_covered_fields' => $confidenceFields,
            'corrected_snapshot_fields' => $snapshotFields,
            'low_confidence_corrected_fields' => $lowConfidenceCorrectedFields,
            'provider_candidate' => $providerCandidate,
            'parser_proposal_avoidable' => $this->parserProposalAvoidable($signals, $reasonCodes),
            'duplicate_manual_review' => $this->isDuplicateManualReview($routingAction, $reasonCodes),
            'conflict_risk' => $conflictRisk,
            'ocr_attempt_engine_counts' => $this->ocrAttemptEngineCounts($intake->ocrAttempts),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function summary(Collection $rows, array $fields, int $minSamples): array
    {
        $actorCounts = array_fill_keys(self::ACTOR_BUCKETS, 0);
        $surfaceCounts = array_fill_keys(self::SURFACE_BUCKETS, 0);
        $ocrAttemptCounts = [
            'ml_kit' => 0,
            'laravel_native_ocr' => 0,
            'sarvam' => 0,
            'unknown' => 0,
        ];
        $fieldConfidenceCoverage = array_fill_keys($fields, 0);
        $correctedSnapshotCoverage = array_fill_keys($fields, 0);
        $lowConfidenceCorrectedCounts = array_fill_keys($fields, 0);
        $reviewedCount = 0;
        $unreviewedCount = 0;
        $providerCandidateCount = 0;
        $parserProposalAvoidableCount = 0;
        $duplicateManualReviewCount = 0;
        $learningCandidateCount = 0;
        $conflictRiskCount = 0;

        foreach ($rows as $row) {
            if (! empty($row['has_reviewed_snapshot'])) {
                $reviewedCount++;
            } else {
                $unreviewedCount++;
            }

            $actor = $this->safeToken($row['review_actor_type'] ?? null, 'unknown');
            $actorCounts[$actor] = ($actorCounts[$actor] ?? 0) + 1;

            $surface = $this->safeToken($row['review_surface'] ?? null, 'unknown');
            $surfaceCounts[$surface] = ($surfaceCounts[$surface] ?? 0) + 1;

            foreach ($this->arrayValue($row['ocr_attempt_engine_counts'] ?? []) as $engine => $count) {
                $engine = $this->safeToken($engine, 'unknown');
                $ocrAttemptCounts[$engine] = ($ocrAttemptCounts[$engine] ?? 0) + (int) $count;
            }

            foreach ($this->tokenList($row['field_confidence_covered_fields'] ?? []) as $field) {
                $fieldConfidenceCoverage[$field] = ($fieldConfidenceCoverage[$field] ?? 0) + 1;
            }

            foreach ($this->tokenList($row['corrected_snapshot_fields'] ?? []) as $field) {
                $correctedSnapshotCoverage[$field] = ($correctedSnapshotCoverage[$field] ?? 0) + 1;
            }

            foreach ($this->tokenList($row['low_confidence_corrected_fields'] ?? []) as $field) {
                $lowConfidenceCorrectedCounts[$field] = ($lowConfidenceCorrectedCounts[$field] ?? 0) + 1;
            }

            if (! empty($row['provider_candidate'])) {
                $providerCandidateCount++;
            }
            if (! empty($row['parser_proposal_avoidable'])) {
                $parserProposalAvoidableCount++;
            }
            if (! empty($row['duplicate_manual_review'])) {
                $duplicateManualReviewCount++;
            }
            if (! empty($row['learning_candidate'])) {
                $learningCandidateCount++;
            }
            if (! empty($row['conflict_risk'])) {
                $conflictRiskCount++;
            }
        }

        $readiness = $this->readinessStatus(
            $reviewedCount,
            $actorCounts,
            $correctedSnapshotCoverage,
            $learningCandidateCount,
            $conflictRiskCount,
            $providerCandidateCount,
            $lowConfidenceCorrectedCounts,
            $minSamples
        );

        return [
            'total_intakes_scanned' => $rows->count(),
            'reviewed_snapshot_count' => $reviewedCount,
            'unreviewed_count' => $unreviewedCount,
            'actor_provenance_counts' => $actorCounts,
            'surface_counts' => $surfaceCounts,
            'ocr_attempt_counts' => $ocrAttemptCounts,
            'field_confidence_coverage_by_field' => $fieldConfidenceCoverage,
            'corrected_snapshot_coverage_by_field' => $correctedSnapshotCoverage,
            'low_confidence_corrected_count_by_field' => $lowConfidenceCorrectedCounts,
            'provider_candidate_count' => $providerCandidateCount,
            'parser_proposal_avoidable_count' => $parserProposalAvoidableCount,
            'duplicate_manual_review_count' => $duplicateManualReviewCount,
            'learning_candidate_count' => $learningCandidateCount,
            'conflict_risk_count' => $conflictRiskCount,
            'learning_readiness_status' => $readiness['status'],
            'blockers' => $readiness['blockers'],
            'warnings' => $readiness['warnings'],
        ];
    }

    /**
     * @param  array<string, int>  $actorCounts
     * @param  array<string, int>  $correctedSnapshotCoverage
     * @param  array<string, int>  $lowConfidenceCorrectedCounts
     * @return array{status: string, blockers: list<string>, warnings: list<string>}
     */
    private function readinessStatus(
        int $reviewedCount,
        array $actorCounts,
        array $correctedSnapshotCoverage,
        int $learningCandidateCount,
        int $conflictRiskCount,
        int $providerCandidateCount,
        array $lowConfidenceCorrectedCounts,
        int $minSamples
    ): array {
        $blockers = [];
        $warnings = [];
        $authorizedActorCount = (int) ($actorCounts['admin'] ?? 0)
            + (int) ($actorCounts['profile_user'] ?? 0)
            + (int) ($actorCounts['suchak'] ?? 0);
        $unknownOrSystemActorCount = (int) ($actorCounts['unknown'] ?? 0) + (int) ($actorCounts['system'] ?? 0);
        $fieldCoverageTotal = array_sum($correctedSnapshotCoverage);
        $lowConfidenceCorrectedTotal = array_sum($lowConfidenceCorrectedCounts);

        if ($reviewedCount === 0) {
            $blockers[] = 'no_reviewed_snapshots';
        }

        if ($reviewedCount > 0 && $unknownOrSystemActorCount > max(0, intdiv($reviewedCount, 2))) {
            $blockers[] = 'actor_provenance_missing_heavily';
        }

        if ($reviewedCount > 0 && $fieldCoverageTotal === 0) {
            $blockers[] = 'corrected_snapshot_field_coverage_missing';
        }

        if ($blockers !== []) {
            return [
                'status' => 'not_ready',
                'blockers' => $blockers,
                'warnings' => $warnings,
            ];
        }

        if ($learningCandidateCount < $minSamples) {
            $warnings[] = 'min_samples_not_met_for_candidate_rules';
        }

        if ($authorizedActorCount < $reviewedCount) {
            $warnings[] = 'actor_provenance_incomplete_for_candidate_rules';
        }

        if ($conflictRiskCount > 0) {
            $warnings[] = 'conflict_risk_present';
        }

        if ($providerCandidateCount > 0) {
            $warnings[] = 'provider_candidates_present';
        }

        if ($lowConfidenceCorrectedTotal > 0) {
            $warnings[] = 'low_confidence_corrected_samples_present';
        }

        if (
            $learningCandidateCount >= $minSamples
            && $authorizedActorCount === $reviewedCount
            && $conflictRiskCount === 0
            && $providerCandidateCount === 0
        ) {
            return [
                'status' => 'ready_for_candidate_rules',
                'blockers' => [],
                'warnings' => $warnings,
            ];
        }

        return [
            'status' => 'ready_for_offline_analysis',
            'blockers' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);

        $this->table(['Metric', 'Value'], [
            ['Total intakes scanned', $summary['total_intakes_scanned'] ?? 0],
            ['Reviewed snapshot count', $summary['reviewed_snapshot_count'] ?? 0],
            ['Unreviewed count', $summary['unreviewed_count'] ?? 0],
            ['Provider candidate count', $summary['provider_candidate_count'] ?? 0],
            ['Parser proposal avoidable count', $summary['parser_proposal_avoidable_count'] ?? 0],
            ['Duplicate/manual review count', $summary['duplicate_manual_review_count'] ?? 0],
            ['Learning candidate count', $summary['learning_candidate_count'] ?? 0],
            ['Learning readiness status', $summary['learning_readiness_status'] ?? 'not_ready'],
            ['Blockers', implode(',', $this->tokenList($summary['blockers'] ?? [])) ?: '-'],
            ['Warnings', implode(',', $this->tokenList($summary['warnings'] ?? [])) ?: '-'],
        ]);

        $this->table(['Actor', 'Count'], $this->countRows($this->arrayValue($summary['actor_provenance_counts'] ?? [])));
        $this->table(['Surface', 'Count'], $this->countRows($this->arrayValue($summary['surface_counts'] ?? [])));
        $this->table(['OCR engine', 'Count'], $this->countRows($this->arrayValue($summary['ocr_attempt_counts'] ?? [])));
        $this->table(['Field', 'Confidence coverage'], $this->countRows($this->arrayValue($summary['field_confidence_coverage_by_field'] ?? [])));
        $this->table(['Field', 'Corrected snapshot coverage'], $this->countRows($this->arrayValue($summary['corrected_snapshot_coverage_by_field'] ?? [])));
        $this->table(['Field', 'Low-confidence corrected'], $this->countRows($this->arrayValue($summary['low_confidence_corrected_count_by_field'] ?? [])));

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Reviewed',
            'Actor',
            'Surface',
            'OCR attempts',
            'Sarvam',
            'Routing action',
            'Reason codes',
            'Learning candidate',
            'Blockers',
        ], array_map(fn (array $row): array => [
            $row['intake_id'],
            $this->yesNo($row['has_reviewed_snapshot'] ?? null),
            $this->safeToken($row['review_actor_type'] ?? null, 'unknown'),
            $this->safeToken($row['review_surface'] ?? null, 'unknown'),
            $this->yesNo($row['has_ocr_attempts'] ?? null),
            $this->yesNo($row['has_sarvam_attempt'] ?? null),
            $this->safeToken($row['routing_action'] ?? null, 'unknown'),
            implode(',', $this->tokenList($row['reason_codes'] ?? [])) ?: '-',
            $this->yesNo($row['learning_candidate'] ?? null),
            implode(',', $this->tokenList($row['blocker_summary'] ?? [])) ?: '-',
        ], $rows));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function snapshotCoverage(array $snapshot, array $fields): array
    {
        if ($snapshot === []) {
            return [];
        }

        return array_values(array_filter(
            $fields,
            fn (string $field): bool => $this->snapshotHasField($snapshot, $field)
        ));
    }

    /**
     * @param  array<string, mixed>  $fieldConfidence
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function fieldConfidenceCoverage(array $fieldConfidence, array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (string $field): bool => $this->fieldConfidenceSignal($fieldConfidence, $field) !== null
        ));
    }

    /**
     * @param  array<string, mixed>  $fieldConfidence
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function lowConfidenceFields(array $fieldConfidence, array $fields): array
    {
        $low = [];
        foreach ($fields as $field) {
            $signal = $this->fieldConfidenceSignal($fieldConfidence, $field);
            if ($signal !== null && $this->isLowConfidenceSignal($signal)) {
                $low[] = $field;
            }
        }

        return $low;
    }

    /**
     * @param  array<string, mixed>  $fieldConfidence
     * @return array<string, mixed>|null
     */
    private function fieldConfidenceSignal(array $fieldConfidence, string $field): ?array
    {
        $aliases = $this->fieldAliases($field);
        foreach ($fieldConfidence as $key => $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $normalizedKey = $this->normalizeFieldName((string) $key);
            $sourcePath = $this->normalizeFieldName((string) ($signal['source_path'] ?? ''));

            foreach ($aliases as $alias) {
                $normalizedAlias = $this->normalizeFieldName($alias);
                if ($normalizedKey === $normalizedAlias || str_contains($sourcePath, $normalizedAlias)) {
                    return $signal;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function isLowConfidenceSignal(array $signal): bool
    {
        $score = $this->nullableFloat($signal['score'] ?? null);
        if ($score !== null && $score < 0.65) {
            return true;
        }

        $present = $signal['present'] ?? null;
        if ($present === false || $present === 0 || $present === '0' || $present === 'false') {
            return true;
        }

        $status = strtolower($this->safeToken($signal['status'] ?? null, ''));

        return in_array($status, ['low', 'missing', 'unknown', 'failed'], true);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHasField(array $snapshot, string $field): bool
    {
        return match ($field) {
            'full_name' => $this->hasFilledPath($snapshot, ['core.full_name', 'full_name']),
            'date_of_birth' => $this->hasFilledPath($snapshot, ['core.date_of_birth', 'date_of_birth', 'dob']),
            'height' => $this->hasFilledPath($snapshot, ['core.height_cm', 'core.height', 'height_cm', 'height']),
            'education' => $this->hasFilledPath($snapshot, ['core.highest_education', 'highest_education', 'education'])
                || $this->hasFilledArray($snapshot['education_history'] ?? null),
            'occupation' => $this->hasFilledPath($snapshot, ['core.occupation_title', 'occupation_title', 'occupation'])
                || $this->hasFilledArray($snapshot['career_history'] ?? null),
            'primary_contact_number' => $this->hasFilledPath($snapshot, ['core.primary_contact_number', 'primary_contact_number'])
                || $this->hasFilledContact($snapshot['contacts'] ?? null),
            'address' => $this->hasFilledPath($snapshot, ['core.current_address', 'current_address', 'address'])
                || $this->hasFilledArray($snapshot['addresses'] ?? null)
                || $this->hasFilledArray($snapshot['self_addresses'] ?? null)
                || $this->hasFilledArray($snapshot['parents_addresses'] ?? null),
            'religion' => $this->hasFilledPath($snapshot, ['core.religion', 'core.religion_id', 'religion', 'religion_id']),
            'caste' => $this->hasFilledPath($snapshot, ['core.caste', 'core.caste_id', 'caste', 'caste_id']),
            default => false,
        };
    }

    /**
     * @param  list<string>  $paths
     */
    private function hasFilledPath(array $payload, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($this->hasFilledValue(data_get($payload, $path))) {
                return true;
            }
        }

        return false;
    }

    private function hasFilledArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    private function hasFilledContact(mixed $contacts): bool
    {
        if (! is_array($contacts)) {
            return false;
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $isPrimary = (bool) ($contact['is_primary'] ?? false);
            if (($isPrimary || ! array_key_exists('is_primary', $contact)) && $this->hasFilledValue($contact['phone_number'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function hasFilledValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        return trim((string) $value) !== '';
    }

    /**
     * @return list<string>
     */
    private function fieldAliases(string $field): array
    {
        return match ($field) {
            'full_name' => ['full_name', 'name', 'core.full_name'],
            'date_of_birth' => ['date_of_birth', 'dob', 'birth_date', 'core.date_of_birth'],
            'height' => ['height', 'height_cm', 'core.height_cm'],
            'education' => ['education', 'highest_education', 'education_history'],
            'occupation' => ['occupation', 'occupation_title', 'career_history'],
            'primary_contact_number' => ['primary_contact_number', 'phone_number', 'mobile', 'contact_number'],
            'address' => ['address', 'current_address', 'addresses', 'self_addresses', 'parents_addresses'],
            'religion' => ['religion', 'religion_id'],
            'caste' => ['caste', 'caste_id'],
            default => [$field],
        };
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     * @return array{ml_kit: int, laravel_native_ocr: int, sarvam: int, unknown: int}
     */
    private function ocrAttemptEngineCounts(Collection $attempts): array
    {
        $counts = [
            'ml_kit' => 0,
            'laravel_native_ocr' => 0,
            'sarvam' => 0,
            'unknown' => 0,
        ];

        foreach ($attempts as $attempt) {
            $bucket = match ($attempt->engine) {
                BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER => 'ml_kit',
                BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR => 'laravel_native_ocr',
                BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION => 'sarvam',
                default => 'unknown',
            };
            $counts[$bucket]++;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $reasonCodes
     */
    private function isProviderCandidate(string $routingAction, array $signals, array $reasonCodes): bool
    {
        return $routingAction === 'call_sarvam'
            || $this->safeToken($signals['critical_field_parser_proposal_outcome'] ?? null, '') === 'provider_candidate'
            || in_array('critical_field_raw_evidence_absent', $reasonCodes, true);
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $failureCodes
     */
    private function hasConflictRisk(string $routingAction, array $reasonCodes, array $failureCodes, bool $providerCandidate): bool
    {
        if ($providerCandidate) {
            return true;
        }

        foreach (array_merge($reasonCodes, $failureCodes) as $code) {
            if (
                str_starts_with($code, 'duplicate_')
                || in_array($code, ['provider_error', 'parser_no_fields', 'empty_text'], true)
            ) {
                return true;
            }
        }

        return $routingAction === 'reuse_previous';
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function parserProposalAvoidable(array $signals, array $reasonCodes): bool
    {
        return $this->boolValue($signals['estimated_paid_vision_avoidable'] ?? false)
            || in_array('critical_field_parser_proposal_available', $reasonCodes, true)
            || in_array('paid_vision_not_required_due_to_parser_proposal', $reasonCodes, true);
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function isDuplicateManualReview(string $routingAction, array $reasonCodes): bool
    {
        if ($routingAction === 'manual_review') {
            return true;
        }

        foreach ($reasonCodes as $code) {
            if (str_starts_with($code, 'duplicate_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function rowBlockers(bool $hasReviewedSnapshot, string $actor, array $snapshotFields, bool $providerCandidate, bool $conflictRisk): array
    {
        $blockers = [];
        if (! $hasReviewedSnapshot) {
            $blockers[] = 'no_reviewed_snapshot';
        }
        if (! in_array($actor, self::ACTORS, true)) {
            $blockers[] = 'review_actor_not_authorized';
        }
        if ($snapshotFields === []) {
            $blockers[] = 'no_corrected_field_coverage';
        }
        if ($providerCandidate) {
            $blockers[] = 'provider_candidate';
        } elseif ($conflictRisk) {
            $blockers[] = 'conflict_risk';
        }

        return $blockers;
    }

    /**
     * @param  list<string>  $reasonCodes
     * @return list<string>
     */
    private function importantReasonCodes(array $reasonCodes): array
    {
        $allowed = [
            'field_confidence_low',
            'critical_field_parser_proposal_available',
            'paid_vision_not_required_due_to_parser_proposal',
            'critical_field_parser_proposal_ambiguous',
            'critical_field_raw_evidence_absent',
            'duplicate_detected',
            'duplicate_detected_but_untrusted',
            'backfilled_quality_not_trusted',
            'reference_lacks_verifiable_ocr_evidence',
            'parser_no_fields',
            'no_signal',
            'low_quality_cheap_ocr',
            'provider_error',
        ];

        return array_values(array_intersect($reasonCodes, $allowed));
    }

    private function actorOption(): false|string|null
    {
        $actor = $this->tokenOption('actor');
        if ($actor !== null && ! in_array($actor, self::ACTORS, true)) {
            $this->error('Invalid --actor. Use one of: '.implode(', ', self::ACTORS));

            return false;
        }

        return $actor;
    }

    private function fieldOption(): false|string|null
    {
        $field = $this->tokenOption('field');
        if ($field !== null && ! in_array($field, self::FIELDS, true)) {
            $this->error('Invalid --field. Use one of: '.implode(', ', self::FIELDS));

            return false;
        }

        return $field;
    }

    private function tokenOption(string $option): ?string
    {
        $value = $this->option($option);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->safeToken($value);
    }

    private function actorBucket(mixed $value): string
    {
        $actor = $this->safeToken($value, 'unknown');

        return in_array($actor, self::ACTOR_BUCKETS, true) ? $actor : 'unknown';
    }

    private function surfaceBucket(mixed $value): string
    {
        $surface = $this->safeToken($value, 'unknown');

        return in_array($surface, self::SURFACE_BUCKETS, true) ? $surface : 'unknown';
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

        return array_values(array_filter(array_map(
            fn (mixed $item): string => $this->safeToken($item),
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    private function safeToken(mixed $value, ?string $default = ''): ?string
    {
        if (! is_scalar($value)) {
            return $default;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return $default;
        }

        $string = preg_replace('/sk-[A-Za-z0-9_-]+/i', '[redacted-secret]', $string) ?? $string;
        $string = preg_replace('/\b[A-Fa-f0-9]{32,}\b/', '[redacted-hash]', $string) ?? $string;
        $string = preg_replace('/(?<!\d)\+?\d[\d\s().-]{7,}\d(?!\d)/', '[redacted-number]', $string) ?? $string;
        $string = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $string) ?? $string;

        return Str::limit($string, 80, '');
    }

    private function normalizeFieldName(string $value): string
    {
        return str_replace(['.', '-'], '_', strtolower($value));
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', 'yes'], true);
        }

        return false;
    }

    private function yesNo(mixed $value): string
    {
        if ($value === null) {
            return 'unknown';
        }

        return $this->boolValue($value) ? 'yes' : 'no';
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return list<array{0: string, 1: int}>
     */
    private function countRows(array $counts): array
    {
        return array_map(
            fn (string $key, mixed $count): array => [$key, (int) $count],
            array_keys($counts),
            array_values($counts)
        );
    }
}

