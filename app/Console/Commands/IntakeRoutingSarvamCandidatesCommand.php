<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Intake\IntakeFieldConfidenceClassifier;
use App\Services\Intake\IntakeSmartRoutingPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IntakeRoutingSarvamCandidatesCommand extends Command
{
    private const QUALITY_BUCKETS = [
        '0-0.49',
        '0.5-0.74',
        '0.75-0.89',
        '0.9+',
        'unknown',
    ];

    protected $signature = 'intake:routing-sarvam-candidates
        {--limit=100 : Maximum latest intakes with routing dry-run data to inspect}
        {--json : Print the report as JSON}
        {--reason= : Include only call_sarvam candidates with this reason code}
        {--include-locked : Include locked intakes}
        {--min-quality=0.0 : Minimum quality score to include}
        {--max-quality=1.0 : Maximum quality score to include}';

    protected $description = 'Read-only inspection report for dry-run call_sarvam biodata intake candidates.';

    public function __construct(
        private readonly IntakeSmartRoutingPolicy $policy,
        private readonly IntakeFieldConfidenceClassifier $fieldConfidenceClassifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $json = (bool) $this->option('json');
        $includeLocked = (bool) $this->option('include-locked');
        $reason = $this->reasonOption();
        $minQuality = $this->qualityOption('min-quality', 0.0);
        $maxQuality = $this->qualityOption('max-quality', 1.0);

        if ($minQuality === false || $maxQuality === false) {
            return self::FAILURE;
        }

        if ($minQuality > $maxQuality) {
            $this->error('--min-quality must be less than or equal to --max-quality.');

            return self::FAILURE;
        }

        $intakes = $this->loadIntakes($limit, $includeLocked);
        $rows = $intakes
            ->map(fn (BiodataIntake $intake): ?array => $this->candidateRow($intake, $reason, $minQuality, $maxQuality))
            ->filter()
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'reason' => $reason,
                'include_locked' => $includeLocked,
                'min_quality' => $minQuality,
                'max_quality' => $maxQuality,
            ],
            'summary' => $this->summary($rows),
            'rows' => $rows->all(),
        ];

        if ($json) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, BiodataIntake>
     */
    private function loadIntakes(int $limit, bool $includeLocked): Collection
    {
        $query = BiodataIntake::query()
            ->select([
                'id',
                'raw_ocr_text',
                'parsed_json',
                'parse_status',
                'intake_locked',
                'quality_summary_json',
                'failure_codes_json',
                'field_confidence_json',
                'routing_recommendation_json',
                'routing_telemetry_json',
                'matrimony_profile_id',
                'created_at',
            ])
            ->with(['ocrAttempts' => function ($query): void {
                $query->select([
                    'id',
                    'intake_id',
                    'engine',
                    'status',
                    'quality_score',
                    'is_primary',
                ]);
            }])
            ->whereNotNull('routing_recommendation_json')
            ->latest('id')
            ->limit($limit);

        if (! $includeLocked) {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('intake_locked')
                    ->orWhere('intake_locked', false);
            });
        }

        return $query->get();
    }

    private function candidateRow(BiodataIntake $intake, ?string $reason, float $minQuality, float $maxQuality): ?array
    {
        $recommendation = $this->arrayValue($intake->routing_recommendation_json);
        $recommendedAction = $this->scalarString($recommendation['recommended_action'] ?? null);

        if ($recommendedAction !== 'call_sarvam') {
            return null;
        }

        $reasonCodes = $this->stringList($recommendation['reason_codes'] ?? []);
        if ($reason !== null && ! in_array($reason, $reasonCodes, true)) {
            return null;
        }

        $signals = $this->arrayValue($recommendation['signals'] ?? []);
        $telemetry = $this->arrayValue($intake->routing_telemetry_json);
        $qualityScore = $this->qualityScore($intake, $signals, $telemetry);
        if (! $this->qualityWithinRange($qualityScore, $minQuality, $maxQuality)) {
            return null;
        }

        $attempts = $this->ocrAttempts($intake);
        $policyEvaluation = $this->policy->evaluate($recommendation);
        $lowConfidenceFields = $this->lowConfidenceFields($intake, $signals);
        $fieldClassification = $this->fieldClassification($intake, $signals, $lowConfidenceFields);

        return [
            'intake_id' => (int) $intake->id,
            'recommended_action' => $recommendedAction,
            'reason_codes' => $reasonCodes,
            'quality_score' => $qualityScore,
            'quality_bucket' => $this->qualityBucket($qualityScore),
            'field_confidence_low_fields' => $lowConfidenceFields,
            'field_confidence_critical_fields' => $fieldClassification['low_confidence_critical_fields'],
            'field_confidence_important_fields' => $fieldClassification['low_confidence_important_fields'],
            'field_confidence_optional_fields' => $fieldClassification['low_confidence_optional_fields'],
            'field_confidence_routing_severity' => $fieldClassification['field_confidence_routing_severity'],
            'paid_vision_reasonable_for_field_confidence' => $fieldClassification['paid_vision_reasonable_for_field_confidence'],
            'failure_codes' => $this->failureCodes($intake, $signals),
            'has_raw_ocr_text' => $this->boolSignal($signals['has_raw_ocr_text'] ?? null, trim((string) ($intake->raw_ocr_text ?? '')) !== ''),
            'has_parsed_json' => $this->boolSignal($signals['has_parsed_json'] ?? null, is_array($intake->parsed_json) && $intake->parsed_json !== []),
            'ocr_attempt_count' => $attempts->count(),
            'cheap_ocr_attempt_count' => $this->cheapOcrAttemptCount($attempts),
            'sarvam_attempt_count' => $this->sarvamAttemptCount($attempts),
            'primary_ocr_attempt_exists' => $attempts->contains(fn (BiodataIntakeOcrAttempt $attempt): bool => (bool) $attempt->is_primary),
            'duplicate' => $this->duplicateSummary($signals),
            'policy' => $this->policySummary($policyEvaluation),
            'notes' => $this->notes($reasonCodes, $signals, $attempts),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $reasonCounts = [];
        $qualityBuckets = array_fill_keys(self::QUALITY_BUCKETS, 0);
        $parsedCounts = ['yes' => 0, 'no' => 0];
        $rawCounts = ['yes' => 0, 'no' => 0];
        $sarvamCounts = ['yes' => 0, 'no' => 0];

        foreach ($rows as $row) {
            foreach ($this->stringList($row['reason_codes'] ?? []) as $reasonCode) {
                $reasonCounts[$reasonCode] = ($reasonCounts[$reasonCode] ?? 0) + 1;
            }

            $qualityBucket = $this->scalarString($row['quality_bucket'] ?? null);
            $qualityBuckets[$qualityBucket] = ($qualityBuckets[$qualityBucket] ?? 0) + 1;

            $parsedCounts[! empty($row['has_parsed_json']) ? 'yes' : 'no']++;
            $rawCounts[! empty($row['has_raw_ocr_text']) ? 'yes' : 'no']++;
            $sarvamCounts[((int) ($row['sarvam_attempt_count'] ?? 0)) > 0 ? 'yes' : 'no']++;
        }

        arsort($reasonCounts);

        return [
            'total_call_sarvam_candidates' => $rows->count(),
            'reason_code_counts' => $reasonCounts,
            'quality_bucket_counts' => $qualityBuckets,
            'parsed_json_counts' => $parsedCounts,
            'raw_ocr_text_counts' => $rawCounts,
            'existing_sarvam_attempt_counts' => $sarvamCounts,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);
        $this->table(['Metric', 'Value'], [
            ['Total call_sarvam candidates', $summary['total_call_sarvam_candidates'] ?? 0],
            ['Parsed JSON yes/no', $this->countPair($this->arrayValue($summary['parsed_json_counts'] ?? []))],
            ['Raw OCR text yes/no', $this->countPair($this->arrayValue($summary['raw_ocr_text_counts'] ?? []))],
            ['Existing Sarvam attempt yes/no', $this->countPair($this->arrayValue($summary['existing_sarvam_attempt_counts'] ?? []))],
        ]);

        $this->table(
            ['Reason code', 'Count'],
            $this->countRows($this->arrayValue($summary['reason_code_counts'] ?? []), 'none')
        );

        $this->table(
            ['Quality bucket', 'Count'],
            $this->countRows($this->arrayValue($summary['quality_bucket_counts'] ?? []), 'none')
        );

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Action',
            'Reasons',
            'Quality',
            'Low fields',
            'Critical fields',
            'Important fields',
            'Optional fields',
            'Field severity',
            'Paid vision reasonable',
            'Failure codes',
            'Raw',
            'Parsed',
            'OCR',
            'Cheap',
            'Sarvam',
            'Primary',
            'Duplicate',
            'Policy',
            'Notes',
        ], array_map(fn (array $row): array => [
            $row['intake_id'],
            $row['recommended_action'],
            implode(',', $this->stringList($row['reason_codes'] ?? [])) ?: '-',
            $row['quality_score'] ?? 'n/a',
            implode(',', $this->stringList($row['field_confidence_low_fields'] ?? [])) ?: '-',
            implode(',', $this->stringList($row['field_confidence_critical_fields'] ?? [])) ?: '-',
            implode(',', $this->stringList($row['field_confidence_important_fields'] ?? [])) ?: '-',
            implode(',', $this->stringList($row['field_confidence_optional_fields'] ?? [])) ?: '-',
            $this->safeToken($row['field_confidence_routing_severity'] ?? null),
            $this->yesNo($row['paid_vision_reasonable_for_field_confidence'] ?? null),
            implode(',', $this->stringList($row['failure_codes'] ?? [])) ?: '-',
            $this->yesNo($row['has_raw_ocr_text'] ?? null),
            $this->yesNo($row['has_parsed_json'] ?? null),
            $row['ocr_attempt_count'] ?? 'n/a',
            $row['cheap_ocr_attempt_count'] ?? 'n/a',
            $row['sarvam_attempt_count'] ?? 'n/a',
            $this->yesNo($row['primary_ocr_attempt_exists'] ?? null),
            $this->duplicateDisplay($this->arrayValue($row['duplicate'] ?? [])),
            $this->policyDisplay($this->arrayValue($row['policy'] ?? [])),
            implode(',', $this->stringList($row['notes'] ?? [])) ?: '-',
        ], $rows));
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $telemetry
     */
    private function qualityScore(BiodataIntake $intake, array $signals, array $telemetry): ?float
    {
        return $this->nullableFloat(
            $signals['quality_score']
            ?? data_get($intake->quality_summary_json, 'score')
            ?? $telemetry['last_quality_score']
            ?? null
        );
    }

    private function qualityWithinRange(?float $qualityScore, float $minQuality, float $maxQuality): bool
    {
        if ($qualityScore === null) {
            return $minQuality <= 0.0 && $maxQuality >= 1.0;
        }

        return $qualityScore >= $minQuality && $qualityScore <= $maxQuality;
    }

    private function qualityBucket(?float $qualityScore): string
    {
        if ($qualityScore === null) {
            return 'unknown';
        }

        return match (true) {
            $qualityScore < 0.5 => '0-0.49',
            $qualityScore < 0.75 => '0.5-0.74',
            $qualityScore < 0.9 => '0.75-0.89',
            default => '0.9+',
        };
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    private function lowConfidenceFields(BiodataIntake $intake, array $signals): array
    {
        $fromSignals = $this->stringList($signals['low_confidence_fields'] ?? []);
        if ($fromSignals !== []) {
            return $fromSignals;
        }

        $fields = [];
        $fieldConfidence = is_array($intake->field_confidence_json) ? $intake->field_confidence_json : [];
        foreach ($fieldConfidence as $field => $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $score = $this->nullableFloat($signal['score'] ?? null);
            $present = $signal['present'] ?? null;
            $status = strtolower(trim((string) ($signal['status'] ?? '')));
            if (($score !== null && $score < 0.65) || $present === false || in_array($status, ['low', 'missing', 'unknown'], true)) {
                $fields[] = (string) $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $lowConfidenceFields
     * @return array{
     *     low_confidence_critical_fields: list<string>,
     *     low_confidence_important_fields: list<string>,
     *     low_confidence_optional_fields: list<string>,
     *     field_confidence_routing_severity: string,
     *     paid_vision_reasonable_for_field_confidence: bool
     * }
     */
    private function fieldClassification(BiodataIntake $intake, array $signals, array $lowConfidenceFields): array
    {
        $hasRawOcrText = $this->boolSignal($signals['has_raw_ocr_text'] ?? null, trim((string) ($intake->raw_ocr_text ?? '')) !== '');
        $computed = $this->fieldConfidenceClassifier->classify($lowConfidenceFields, $hasRawOcrText);
        $critical = $this->stringList($signals['low_confidence_critical_fields'] ?? []);
        $important = $this->stringList($signals['low_confidence_important_fields'] ?? []);
        $optional = $this->stringList($signals['low_confidence_optional_fields'] ?? []);
        $severity = $this->scalarString($signals['field_confidence_routing_severity'] ?? null);
        $paidVisionReasonable = array_key_exists('paid_vision_reasonable_for_field_confidence', $signals)
            ? $this->boolValue($signals['paid_vision_reasonable_for_field_confidence'])
            : (bool) $computed['paid_vision_reasonable_for_field_confidence'];

        return [
            'low_confidence_critical_fields' => $critical !== [] ? $critical : $computed['low_confidence_critical_fields'],
            'low_confidence_important_fields' => $important !== [] ? $important : $computed['low_confidence_important_fields'],
            'low_confidence_optional_fields' => $optional !== [] ? $optional : $computed['low_confidence_optional_fields'],
            'field_confidence_routing_severity' => $severity !== '' ? $severity : $computed['field_confidence_routing_severity'],
            'paid_vision_reasonable_for_field_confidence' => $paidVisionReasonable,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    private function failureCodes(BiodataIntake $intake, array $signals): array
    {
        $fromIntake = $this->stringList($intake->failure_codes_json);

        return $fromIntake !== [] ? $fromIntake : $this->stringList($signals['failure_codes'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function duplicateSummary(array $signals): array
    {
        return [
            'detected' => $this->nullableBool($signals['duplicate_detected'] ?? null),
            'reuse_eligible' => $this->nullableBool($signals['duplicate_reuse_eligible'] ?? null),
            'trust' => $this->safeToken($signals['duplicate_reuse_trust'] ?? null),
            'reference_intake_id' => $this->nullableInt($signals['duplicate_reference_intake_id'] ?? null),
            'reference_reason' => $this->safeToken($signals['duplicate_reference_reason'] ?? null),
            'reference_verifiable_ocr_evidence' => $this->nullableBool($signals['duplicate_reference_has_verifiable_ocr_evidence'] ?? null),
            'reference_quality_source' => $this->safeToken($signals['duplicate_reference_quality_source'] ?? null),
            'backfilled_quality_not_trusted' => $this->nullableBool($signals['backfilled_quality_not_trusted'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $policyEvaluation
     * @return array<string, mixed>
     */
    private function policySummary(array $policyEvaluation): array
    {
        return [
            'enabled' => $this->nullableBool($policyEvaluation['enabled'] ?? null),
            'dry_run_only' => $this->nullableBool($policyEvaluation['dry_run_only'] ?? null),
            'allowed_live_action' => $this->safeToken($policyEvaluation['allowed_live_action'] ?? null, 'none'),
            'blocked_reason' => $this->safeToken($policyEvaluation['blocked_reason'] ?? null, 'none'),
        ];
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, mixed>  $signals
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     * @return list<string>
     */
    private function notes(array $reasonCodes, array $signals, Collection $attempts): array
    {
        $notes = [];
        if (in_array('field_confidence_low', $reasonCodes, true)) {
            $notes[] = 'low_field_confidence';
        }
        if (! empty($signals['paid_vision_reasonable_for_field_confidence'])) {
            $notes[] = 'paid_vision_reasonable_for_field_confidence';
        }
        if (($signals['field_confidence_routing_severity'] ?? null) === 'critical') {
            $notes[] = 'critical_field_confidence_low';
        }
        if (in_array('low_quality_cheap_ocr', $reasonCodes, true)) {
            $notes[] = 'low_quality_signal';
        }
        if (in_array('parser_no_fields', $reasonCodes, true)) {
            $notes[] = 'parser_no_fields';
        }
        if (in_array('two_column_layout_suspected', $reasonCodes, true)) {
            $notes[] = 'layout_risk';
        }
        if (! empty($signals['duplicate_detected'])) {
            $notes[] = empty($signals['duplicate_reuse_eligible']) ? 'duplicate_untrusted' : 'duplicate_signal_present';
        }
        if ($this->sarvamAttemptCount($attempts) > 0) {
            $notes[] = 'existing_sarvam_attempt';
        }

        return array_values(array_unique($notes));
    }

    private function reasonOption(): ?string
    {
        $reason = $this->option('reason');
        if ($reason === null || trim((string) $reason) === '') {
            return null;
        }

        return $this->safeToken($reason);
    }

    private function qualityOption(string $option, float $default): float|false
    {
        $value = $this->option($option);
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            $this->error("--{$option} must be numeric.");

            return false;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    /**
     * @return Collection<int, BiodataIntakeOcrAttempt>
     */
    private function ocrAttempts(BiodataIntake $intake): Collection
    {
        $attempts = $intake->getRelation('ocrAttempts');

        return $attempts instanceof Collection ? $attempts : collect();
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function cheapOcrAttemptCount(Collection $attempts): int
    {
        return $attempts
            ->filter(fn (BiodataIntakeOcrAttempt $attempt): bool => in_array($attempt->engine, [
                BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
                BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
            ], true))
            ->count();
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function sarvamAttemptCount(Collection $attempts): int
    {
        return $attempts
            ->filter(fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->engine === BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION)
            ->count();
    }

    private function duplicateDisplay(array $duplicate): string
    {
        $parts = [
            'detected='.$this->yesNo($duplicate['detected'] ?? null),
            'eligible='.$this->yesNo($duplicate['reuse_eligible'] ?? null),
            'ref='.($this->nullableInt($duplicate['reference_intake_id'] ?? null) ?? 'n/a'),
            'reason='.$this->safeToken($duplicate['reference_reason'] ?? null),
            'verifiable='.$this->yesNo($duplicate['reference_verifiable_ocr_evidence'] ?? null),
            'quality_source='.$this->safeToken($duplicate['reference_quality_source'] ?? null),
        ];

        return implode('; ', $parts);
    }

    private function policyDisplay(array $policy): string
    {
        return implode('; ', [
            'enabled='.$this->yesNo($policy['enabled'] ?? null),
            'dry_run_only='.$this->yesNo($policy['dry_run_only'] ?? null),
            'allowed='.$this->safeToken($policy['allowed_live_action'] ?? null, 'none'),
            'blocked='.$this->safeToken($policy['blocked_reason'] ?? null, 'none'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return list<array{0: string, 1: mixed}>
     */
    private function countRows(array $counts, string $emptyLabel): array
    {
        if ($counts === []) {
            return [[$emptyLabel, 0]];
        }

        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [(string) $key, $count];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    private function countPair(array $counts): string
    {
        return 'yes='.((int) ($counts['yes'] ?? 0)).'; no='.((int) ($counts['no'] ?? 0));
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
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function safeToken(mixed $value, string $fallback = 'n/a'): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        $value = preg_replace('/\b\d{6,}\b/', '[redacted-number]', $value) ?? $value;
        $value = preg_replace('/\bsk-[A-Za-z0-9_-]+\b/i', '[redacted-secret]', $value) ?? $value;

        return strlen($value) > 80 ? substr($value, 0, 77).'...' : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return $this->boolValue($value);
    }

    private function boolSignal(mixed $value, bool $fallback): bool
    {
        if ($value === null) {
            return $fallback;
        }

        return $this->boolValue($value);
    }

    private function yesNo(mixed $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return $this->boolValue($value) ? 'yes' : 'no';
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }
}
