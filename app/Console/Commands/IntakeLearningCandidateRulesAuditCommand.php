<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IntakeLearningCandidateRulesAuditCommand extends Command
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

    private const AUTHORIZED_ACTORS = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
    ];

    private const ACTOR_BUCKETS = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
        BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'unknown',
    ];

    private const VALID_SURFACES = [
        BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
        BiodataIntakeOcrAttempt::SURFACE_API,
    ];

    private const SURFACE_BUCKETS = [
        BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
        BiodataIntakeOcrAttempt::SURFACE_API,
        'unknown',
    ];

    private const PLACEHOLDER_VALUES = [
        '-',
        '--',
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

    protected $signature = 'intake:learning-candidate-rules-audit
        {--limit=500 : Maximum latest reviewed intakes to inspect}
        {--json : Print JSON}
        {--field= : Optional field filter}
        {--actor= : Optional actor filter admin/profile_user/suchak}
        {--min-samples=10 : Minimum samples required per field}
        {--since= : Optional reviewed_at date filter YYYY-MM-DD}';

    protected $description = 'Read-only dry-run audit of future intake learning candidate rule samples.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $minSamples = max(1, (int) $this->option('min-samples'));
        $field = $this->fieldOption();
        $actor = $this->actorOption();
        $since = $this->sinceOption();

        if ($field === false || $actor === false || $since === false) {
            return self::FAILURE;
        }

        $fields = $field === null ? self::FIELDS : [$field];
        $rows = $this->loadIntakes($limit, $actor, $since)
            ->map(fn (BiodataIntake $intake): array => $this->auditRow($intake, $fields))
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'field' => $field,
                'actor' => $actor,
                'min_samples' => $minSamples,
                'since' => $since?->toDateString(),
            ],
            'summary' => $this->summary($rows, $fields, $minSamples),
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
     * @return EloquentCollection<int, BiodataIntake>
     */
    private function loadIntakes(int $limit, ?string $actor, ?Carbon $since): EloquentCollection
    {
        $query = BiodataIntake::query()
            ->select([
                'id',
                'approval_snapshot_json',
                'reviewed_by_user_id',
                'review_actor_type',
                'review_surface',
                'reviewed_at',
                'raw_ocr_text',
                'parsed_json',
                'parse_status',
                'quality_summary_json',
                'failure_codes_json',
                'field_confidence_json',
                'routing_recommendation_json',
                'matrimony_profile_id',
            ])
            ->whereNotNull('approval_snapshot_json')
            ->latest('id')
            ->limit($limit);

        if ($actor !== null) {
            $query->where('review_actor_type', $actor);
        }

        if ($since !== null) {
            $query->whereDate('reviewed_at', '>=', $since->toDateString());
        }

        return $query->get();
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function auditRow(BiodataIntake $intake, array $fields): array
    {
        $snapshot = $this->arrayValue($intake->approval_snapshot_json);
        $fieldConfidence = $this->arrayValue($intake->field_confidence_json);
        $recommendation = $this->arrayValue($intake->routing_recommendation_json);
        $signals = $this->arrayValue($recommendation['signals'] ?? []);
        $reasonCodes = $this->tokenList($recommendation['reason_codes'] ?? []);
        $failureCodes = $this->tokenList($intake->failure_codes_json ?? []);
        $routingAction = $this->safeToken($recommendation['recommended_action'] ?? null, 'unknown');

        $actor = $this->actorBucket($intake->review_actor_type);
        $surface = $this->surfaceBucket($intake->review_surface);
        $reviewerIdPresent = $intake->reviewed_by_user_id !== null;
        $reviewedAtPresent = $intake->reviewed_at !== null;
        $fieldsPresent = $this->snapshotFieldsPresent($snapshot, $fields);
        $lowConfidenceFields = array_values(array_intersect(
            $fieldsPresent,
            $this->lowConfidenceFields($fieldConfidence, $fields)
        ));
        $providerCandidate = $this->isProviderCandidate($routingAction, $signals, $reasonCodes);
        $conflictRisk = $this->hasConflictRisk($routingAction, $signals, $reasonCodes, $failureCodes);
        $baseEligible = $this->hasAuthorizedProvenance($actor, $surface, $reviewerIdPresent, $reviewedAtPresent)
            && ! $providerCandidate
            && ! $conflictRisk;
        $eligibleFields = $baseEligible ? $fieldsPresent : [];
        $blockers = $this->rowBlockers(
            $snapshot !== [],
            $actor,
            $surface,
            $reviewerIdPresent,
            $reviewedAtPresent,
            $fieldsPresent,
            $providerCandidate,
            $conflictRisk
        );

        return [
            'intake_id' => (int) $intake->id,
            'actor' => $actor,
            'surface' => $surface,
            'reviewed_at' => $intake->reviewed_at?->toDateTimeString(),
            'fields_present' => $fieldsPresent,
            'eligible_fields' => $eligibleFields,
            'blocker_codes' => $blockers,
            'low_confidence_corrected_fields' => $lowConfidenceFields,
            'provider_candidate' => $providerCandidate,
            'conflict_risk' => $conflictRisk,
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
        $blockerCounts = [];
        $eligibleLearningSourceRows = 0;

        foreach ($rows as $row) {
            $actor = $this->actorBucket($row['actor'] ?? null);
            $surface = $this->surfaceBucket($row['surface'] ?? null);
            $actorCounts[$actor] = ($actorCounts[$actor] ?? 0) + 1;
            $surfaceCounts[$surface] = ($surfaceCounts[$surface] ?? 0) + 1;

            if ($this->tokenList($row['eligible_fields'] ?? []) !== []) {
                $eligibleLearningSourceRows++;
            }

            foreach ($this->tokenList($row['blocker_codes'] ?? []) as $blocker) {
                $blockerCounts[$blocker] = ($blockerCounts[$blocker] ?? 0) + 1;
            }
        }

        $fieldSummary = $this->fieldCandidateSummary($rows, $fields, $minSamples);
        $dryRunCandidateFields = collect($fieldSummary)
            ->where('candidate_status', 'dry_run_candidate_only')
            ->count();

        return [
            'total_reviewed_snapshots_scanned' => $rows->count(),
            'eligible_learning_source_rows' => $eligibleLearningSourceRows,
            'blocked_rows' => $rows->count() - $eligibleLearningSourceRows,
            'field_candidate_summary' => $fieldSummary,
            'actor_counts' => $actorCounts,
            'surface_counts' => $surfaceCounts,
            'blocker_counts' => $blockerCounts,
            'recommendation' => $this->summaryRecommendation($eligibleLearningSourceRows, $dryRunCandidateFields),
            'safety_status' => $dryRunCandidateFields > 0
                ? 'dry_run_candidates_only_learning_disabled'
                : 'blocked_no_learning_candidates',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function fieldCandidateSummary(Collection $rows, array $fields, int $minSamples): array
    {
        $summary = [];

        foreach ($fields as $field) {
            $sampleCount = 0;
            $actorMix = array_fill_keys(self::AUTHORIZED_ACTORS, 0);
            $surfaceMix = array_fill_keys(self::VALID_SURFACES, 0);
            $lowConfidenceCorrectedCount = 0;
            $conflictRiskCount = 0;
            $providerCandidateCount = 0;

            foreach ($rows as $row) {
                if (! in_array($field, $this->tokenList($row['fields_present'] ?? []), true)) {
                    continue;
                }

                if (! empty($row['provider_candidate'])) {
                    $providerCandidateCount++;
                }
                if (! empty($row['conflict_risk'])) {
                    $conflictRiskCount++;
                }

                if (! in_array($field, $this->tokenList($row['eligible_fields'] ?? []), true)) {
                    continue;
                }

                $sampleCount++;
                $actor = $this->actorBucket($row['actor'] ?? null);
                $surface = $this->surfaceBucket($row['surface'] ?? null);
                if (array_key_exists($actor, $actorMix)) {
                    $actorMix[$actor]++;
                }
                if (array_key_exists($surface, $surfaceMix)) {
                    $surfaceMix[$surface]++;
                }
                if (in_array($field, $this->tokenList($row['low_confidence_corrected_fields'] ?? []), true)) {
                    $lowConfidenceCorrectedCount++;
                }
            }

            $minSamplesMet = $sampleCount >= $minSamples;
            $candidateStatus = match (true) {
                $sampleCount === 0 => 'blocked_no_authorized_samples',
                ! $minSamplesMet => 'blocked_min_samples_not_met',
                default => 'dry_run_candidate_only',
            };

            $summary[$field] = [
                'field' => $field,
                'sample_count' => $sampleCount,
                'actor_mix' => $actorMix,
                'surface_mix' => $surfaceMix,
                'low_confidence_corrected_count' => $lowConfidenceCorrectedCount,
                'conflict_risk_count' => $conflictRiskCount,
                'provider_candidate_count' => $providerCandidateCount,
                'min_samples_met' => $minSamplesMet,
                'candidate_status' => $candidateStatus,
                'recommendation' => $this->fieldRecommendation($candidateStatus),
            ];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);

        $this->table(['Metric', 'Value'], [
            ['Total reviewed snapshots scanned', $summary['total_reviewed_snapshots_scanned'] ?? 0],
            ['Eligible learning source rows', $summary['eligible_learning_source_rows'] ?? 0],
            ['Blocked rows', $summary['blocked_rows'] ?? 0],
            ['Recommendation', $summary['recommendation'] ?? 'keep_learning_disabled'],
            ['Safety status', $summary['safety_status'] ?? 'blocked_no_learning_candidates'],
        ]);

        $this->table(['Actor', 'Count'], $this->countRows($this->arrayValue($summary['actor_counts'] ?? [])));
        $this->table(['Surface', 'Count'], $this->countRows($this->arrayValue($summary['surface_counts'] ?? [])));
        $this->table(['Blocker', 'Count'], $this->countRows($this->arrayValue($summary['blocker_counts'] ?? [])));

        $fieldRows = [];
        foreach ($this->arrayValue($summary['field_candidate_summary'] ?? []) as $field => $fieldSummary) {
            $fieldSummary = $this->arrayValue($fieldSummary);
            $fieldRows[] = [
                $field,
                $fieldSummary['sample_count'] ?? 0,
                $this->yesNo($fieldSummary['min_samples_met'] ?? false),
                $fieldSummary['low_confidence_corrected_count'] ?? 0,
                $fieldSummary['conflict_risk_count'] ?? 0,
                $fieldSummary['provider_candidate_count'] ?? 0,
                $fieldSummary['candidate_status'] ?? 'blocked_no_authorized_samples',
                $fieldSummary['recommendation'] ?? 'collect_more_authorized_reviews',
            ];
        }

        $this->table([
            'Field',
            'Samples',
            'Min samples',
            'Low confidence corrected',
            'Conflict risk',
            'Provider candidate',
            'Status',
            'Recommendation',
        ], $fieldRows);

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Actor',
            'Surface',
            'Reviewed at',
            'Fields present',
            'Eligible fields',
            'Blockers',
        ], array_map(fn (array $row): array => [
            $row['intake_id'] ?? 0,
            $this->actorBucket($row['actor'] ?? null),
            $this->surfaceBucket($row['surface'] ?? null),
            $row['reviewed_at'] ?? '-',
            implode(',', $this->tokenList($row['fields_present'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['eligible_fields'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['blocker_codes'] ?? [])) ?: '-',
        ], $rows));
    }

    private function summaryRecommendation(int $eligibleRows, int $dryRunCandidateFields): string
    {
        if ($dryRunCandidateFields > 0) {
            return 'future_candidate_requires_admin_approval';
        }

        if ($eligibleRows > 0) {
            return 'collect_more_authorized_reviews';
        }

        return 'keep_learning_disabled';
    }

    private function fieldRecommendation(string $candidateStatus): string
    {
        return match ($candidateStatus) {
            'dry_run_candidate_only' => 'future_candidate_requires_admin_approval',
            default => 'collect_more_authorized_reviews',
        };
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function snapshotFieldsPresent(array $snapshot, array $fields): array
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
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->hasFilledValue($item)) {
                return true;
            }
        }

        return false;
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
            return $this->hasFilledArray($value);
        }

        if (is_bool($value)) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return false;
        }

        return ! in_array(Str::of($string)->lower()->squish()->toString(), self::PLACEHOLDER_VALUES, true);
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
     * @return array<string, mixed>|null
     */
    private function fieldConfidenceSignal(array $fieldConfidence, string $field): ?array
    {
        foreach ($fieldConfidence as $key => $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $normalizedKey = $this->normalizeFieldName((string) $key);
            $sourcePath = $this->normalizeFieldName((string) ($signal['source_path'] ?? ''));
            foreach ($this->fieldAliases($field) as $alias) {
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

        $status = $this->safeToken($signal['status'] ?? null, '');

        return in_array($status, ['low', 'missing', 'unknown', 'failed'], true);
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
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $reasonCodes
     */
    private function isProviderCandidate(string $routingAction, array $signals, array $reasonCodes): bool
    {
        return $routingAction === 'call_sarvam'
            || $this->boolValue($signals['would_call_paid_vision'] ?? false)
            || $this->safeToken($signals['critical_field_parser_proposal_outcome'] ?? null, '') === 'provider_candidate'
            || in_array('critical_field_raw_evidence_absent', $reasonCodes, true)
            || in_array('provider_candidate', $reasonCodes, true);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $failureCodes
     */
    private function hasConflictRisk(string $routingAction, array $signals, array $reasonCodes, array $failureCodes): bool
    {
        if ($routingAction === 'reuse_previous' || $this->boolValue($signals['duplicate_detected'] ?? false)) {
            return true;
        }

        foreach (array_merge($reasonCodes, $failureCodes) as $code) {
            if (
                str_starts_with($code, 'duplicate_')
                || in_array($code, ['conflict_risk', 'manual_conflict', 'parser_no_fields', 'provider_error'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasAuthorizedProvenance(string $actor, string $surface, bool $reviewerIdPresent, bool $reviewedAtPresent): bool
    {
        return in_array($actor, self::AUTHORIZED_ACTORS, true)
            && $reviewerIdPresent
            && in_array($surface, self::VALID_SURFACES, true)
            && $reviewedAtPresent;
    }

    /**
     * @param  list<string>  $fieldsPresent
     * @return list<string>
     */
    private function rowBlockers(
        bool $hasReviewedSnapshot,
        string $actor,
        string $surface,
        bool $reviewerIdPresent,
        bool $reviewedAtPresent,
        array $fieldsPresent,
        bool $providerCandidate,
        bool $conflictRisk
    ): array {
        $blockers = [];
        if (! $hasReviewedSnapshot) {
            $blockers[] = 'no_reviewed_snapshot';
        }
        if ($actor === 'unknown' && ! $reviewerIdPresent && $surface === 'unknown') {
            $blockers[] = 'legacy_unknown_provenance';
        }
        if (! in_array($actor, self::AUTHORIZED_ACTORS, true)) {
            $blockers[] = 'system_or_unknown_actor';
        }
        if (! $reviewerIdPresent) {
            $blockers[] = 'missing_reviewer_id';
        }
        if (! in_array($surface, self::VALID_SURFACES, true)) {
            $blockers[] = 'missing_surface';
        }
        if (! $reviewedAtPresent) {
            $blockers[] = 'missing_reviewed_at';
        }
        if ($fieldsPresent === []) {
            $blockers[] = 'no_eligible_field_samples';
        }
        if ($providerCandidate) {
            $blockers[] = 'provider_candidate';
        }
        if ($conflictRisk) {
            $blockers[] = 'duplicate_manual_conflict_risk';
        }

        return array_values(array_unique($blockers));
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

    private function actorOption(): false|string|null
    {
        $actor = $this->tokenOption('actor');
        if ($actor !== null && ! in_array($actor, self::AUTHORIZED_ACTORS, true)) {
            $this->error('Invalid --actor. Use one of: '.implode(', ', self::AUTHORIZED_ACTORS));

            return false;
        }

        return $actor;
    }

    private function sinceOption(): Carbon|false|null
    {
        $value = $this->option('since');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $value);
        } catch (\Throwable) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== (string) $value) {
            $this->error('Invalid --since option. Use YYYY-MM-DD.');

            return false;
        }

        return $date->startOfDay();
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
        if ($actor === 'user') {
            return BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER;
        }

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

        return array_values(array_filter(
            array_map(fn (mixed $item): string => $this->safeToken($item, ''), $value),
            fn (string $item): bool => $item !== ''
        ));
    }

    private function safeToken(mixed $value, string $default = ''): string
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
        return $this->boolValue($value) ? 'yes' : 'no';
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return list<array{0: string, 1: int}>
     */
    private function countRows(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [$this->safeToken($key, 'unknown'), (int) $count];
        }

        return $rows;
    }
}
