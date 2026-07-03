<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Services\Intake\IntakeFieldConfidenceClassifier;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IntakeFieldConfidenceAuditCommand extends Command
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.65;

    private const CONFIDENCE_BUCKETS = [
        '0-0.24',
        '0.25-0.49',
        '0.5-0.74',
        '0.75-0.89',
        '0.9+',
        'unknown',
    ];

    protected $signature = 'intake:field-confidence-audit
        {--limit=100 : Maximum latest intakes with stored confidence/routing data to inspect}
        {--json : Print the report as JSON}
        {--field= : Include only rows where this stored field is low confidence}
        {--action= : Include only rows with this recommended_action}
        {--include-locked : Include locked intakes}';

    protected $description = 'Read-only audit report explaining stored low field-confidence intake signals.';

    public function __construct(private readonly IntakeFieldConfidenceClassifier $fieldConfidenceClassifier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $field = $this->tokenOption('field');
        $action = $this->tokenOption('action');
        $includeLocked = (bool) $this->option('include-locked');

        $intakes = $this->loadIntakes($limit, $includeLocked);
        $rows = $intakes
            ->map(fn (BiodataIntake $intake): ?array => $this->auditRow($intake, $field, $action))
            ->filter()
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'field' => $field,
                'action' => $action,
                'include_locked' => $includeLocked,
            ],
            'summary' => $this->summary($rows),
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
                'created_at',
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('field_confidence_json')
                    ->orWhereNotNull('routing_recommendation_json');
            })
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

    private function auditRow(BiodataIntake $intake, ?string $fieldFilter, ?string $actionFilter): ?array
    {
        $recommendation = $this->arrayValue($intake->routing_recommendation_json);
        $signals = $this->arrayValue($recommendation['signals'] ?? []);
        $telemetry = $this->arrayValue($intake->routing_telemetry_json);
        $recommendedAction = $this->recommendedAction($recommendation);

        if ($actionFilter !== null && $recommendedAction !== $actionFilter) {
            return null;
        }

        $fieldConfidence = $this->arrayValue($intake->field_confidence_json);
        $lowConfidenceFields = $this->lowConfidenceFields($fieldConfidence, $signals);

        if ($fieldFilter !== null) {
            $lowConfidenceFields = array_values(array_intersect($lowConfidenceFields, [$fieldFilter]));
        }

        if ($lowConfidenceFields === []) {
            return null;
        }

        $fieldDetails = $this->fieldDetails($fieldConfidence, $lowConfidenceFields);
        $qualityScore = $this->qualityScore($intake, $signals, $telemetry);
        $hasParsedJson = $this->boolSignal($signals['has_parsed_json'] ?? null, $this->hasNonEmptyArray($intake->parsed_json));
        $hasRawOcrText = $this->boolSignal($signals['has_raw_ocr_text'] ?? null, trim((string) ($intake->raw_ocr_text ?? '')) !== '');
        $fieldClassification = $this->fieldClassification($lowConfidenceFields, $signals, $hasRawOcrText);

        return [
            'intake_id' => (int) $intake->id,
            'recommended_action' => $recommendedAction,
            'reason_codes' => $this->tokenList($recommendation['reason_codes'] ?? []),
            'quality_score' => $qualityScore,
            'low_confidence_fields' => $lowConfidenceFields,
            'field_confidence' => $fieldDetails,
            'missing_fields' => $this->missingFields($fieldDetails),
            'low_confidence_critical_fields' => $fieldClassification['low_confidence_critical_fields'],
            'low_confidence_important_fields' => $fieldClassification['low_confidence_important_fields'],
            'low_confidence_optional_fields' => $fieldClassification['low_confidence_optional_fields'],
            'field_confidence_routing_severity' => $fieldClassification['field_confidence_routing_severity'],
            'paid_vision_reasonable_for_field_confidence' => $fieldClassification['paid_vision_reasonable_for_field_confidence'],
            'has_parsed_json' => $hasParsedJson,
            'has_raw_ocr_text' => $hasRawOcrText,
            'failure_codes' => $this->failureCodes($intake, $signals),
            'parser_proposal_outcome' => $this->safeToken($signals['critical_field_parser_proposal_outcome'] ?? null),
            'estimated_paid_vision_avoidable' => $this->nullableBool($signals['estimated_paid_vision_avoidable'] ?? null),
            'missing_critical_fields_resolved_by_proposal' => $this->nullableBool($signals['missing_critical_fields_resolved_by_proposal'] ?? null),
            'has_ambiguous_critical_proposal' => $this->nullableBool($signals['has_ambiguous_critical_proposal'] ?? null),
            'raw_evidence_absent_fields' => $this->tokenList($signals['critical_field_parser_raw_evidence_absent_fields'] ?? []),
            'notes' => $this->notes($qualityScore, $fieldDetails, $this->tokenList($recommendation['reason_codes'] ?? []), $signals),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $fieldCounts = [];
        $confidenceBuckets = array_fill_keys(self::CONFIDENCE_BUCKETS, 0);
        $actionCounts = [];
        $reasonCodeCounts = [];
        $severityCounts = [];
        $paidVisionReasonableCounts = ['yes' => 0, 'no' => 0];

        foreach ($rows as $row) {
            foreach ($this->tokenList($row['low_confidence_fields'] ?? []) as $field) {
                $fieldCounts[$field] = ($fieldCounts[$field] ?? 0) + 1;
            }

            foreach ($this->arrayValue($row['field_confidence'] ?? []) as $detail) {
                if (! is_array($detail)) {
                    continue;
                }

                $bucket = $this->safeToken($detail['bucket'] ?? null, 'unknown');
                $confidenceBuckets[$bucket] = ($confidenceBuckets[$bucket] ?? 0) + 1;
            }

            $action = $this->safeToken($row['recommended_action'] ?? null, 'unknown');
            $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;

            foreach ($this->tokenList($row['reason_codes'] ?? []) as $reasonCode) {
                $reasonCodeCounts[$reasonCode] = ($reasonCodeCounts[$reasonCode] ?? 0) + 1;
            }

            $severity = $this->safeToken($row['field_confidence_routing_severity'] ?? null, 'none');
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;
            $paidVisionReasonableCounts[! empty($row['paid_vision_reasonable_for_field_confidence']) ? 'yes' : 'no']++;
        }

        ksort($fieldCounts);
        arsort($reasonCodeCounts);
        ksort($actionCounts);

        return [
            'total_scanned' => $rows->count(),
            'field_counts' => $fieldCounts,
            'confidence_bucket_counts' => $confidenceBuckets,
            'recommended_action_counts' => $actionCounts,
            'reason_code_counts' => $reasonCodeCounts,
            'field_confidence_severity_counts' => $severityCounts,
            'paid_vision_reasonable_counts' => $paidVisionReasonableCounts,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);

        $this->table(['Metric', 'Value'], [
            ['Total scanned', $summary['total_scanned'] ?? 0],
        ]);

        $this->table(
            ['Field', 'Count'],
            $this->countRows($this->arrayValue($summary['field_counts'] ?? []), 'none')
        );

        $this->table(
            ['Confidence bucket', 'Count'],
            $this->countRows($this->arrayValue($summary['confidence_bucket_counts'] ?? []), 'none')
        );

        $this->table(
            ['Recommended action', 'Count'],
            $this->countRows($this->arrayValue($summary['recommended_action_counts'] ?? []), 'none')
        );

        $this->table(
            ['Reason code', 'Count'],
            $this->countRows($this->arrayValue($summary['reason_code_counts'] ?? []), 'none')
        );

        $this->table(
            ['Field confidence severity', 'Count'],
            $this->countRows($this->arrayValue($summary['field_confidence_severity_counts'] ?? []), 'none')
        );

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Action',
            'Quality',
            'Low fields',
            'Field confidence',
            'Missing fields',
            'Critical fields',
            'Important fields',
            'Optional fields',
            'Field severity',
            'Paid vision reasonable',
            'Parsed',
            'Raw OCR',
            'Failure codes',
            'Reason codes',
            'Parser proposal outcome',
            'Paid vision avoidable',
            'Resolved by proposal',
            'Ambiguous proposal',
            'Raw absent fields',
            'Notes',
        ], array_map(fn (array $row): array => [
            $row['intake_id'],
            $row['recommended_action'],
            $row['quality_score'] ?? 'n/a',
            implode(',', $this->tokenList($row['low_confidence_fields'] ?? [])) ?: '-',
            $this->fieldConfidenceDisplay($this->arrayValue($row['field_confidence'] ?? [])),
            implode(',', $this->tokenList($row['missing_fields'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['low_confidence_critical_fields'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['low_confidence_important_fields'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['low_confidence_optional_fields'] ?? [])) ?: '-',
            $this->safeToken($row['field_confidence_routing_severity'] ?? null, 'none'),
            $this->yesNo($row['paid_vision_reasonable_for_field_confidence'] ?? null),
            $this->yesNo($row['has_parsed_json'] ?? null),
            $this->yesNo($row['has_raw_ocr_text'] ?? null),
            implode(',', $this->tokenList($row['failure_codes'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['reason_codes'] ?? [])) ?: '-',
            $this->safeToken($row['parser_proposal_outcome'] ?? null),
            $this->yesNo($row['estimated_paid_vision_avoidable'] ?? null),
            $this->yesNo($row['missing_critical_fields_resolved_by_proposal'] ?? null),
            $this->yesNo($row['has_ambiguous_critical_proposal'] ?? null),
            implode(',', $this->tokenList($row['raw_evidence_absent_fields'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['notes'] ?? [])) ?: '-',
        ], $rows));
    }

    /**
     * @param  array<string, mixed>  $fieldConfidence
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    private function lowConfidenceFields(array $fieldConfidence, array $signals): array
    {
        $fields = [];
        foreach ($fieldConfidence as $field => $signal) {
            if (! is_array($signal)) {
                continue;
            }

            if ($this->isLowConfidenceSignal($signal)) {
                $fields[] = $this->safeToken($field);
            }
        }

        foreach ($this->tokenList($signals['low_confidence_fields'] ?? []) as $field) {
            $fields[] = $field;
        }

        return array_values(array_unique(array_filter($fields)));
    }

    /**
     * @param  array<string, mixed>  $fieldConfidence
     * @param  list<string>  $lowConfidenceFields
     * @return array<string, array<string, mixed>>
     */
    private function fieldDetails(array $fieldConfidence, array $lowConfidenceFields): array
    {
        $details = [];
        foreach ($lowConfidenceFields as $field) {
            $signal = $this->arrayValue($fieldConfidence[$field] ?? []);
            $score = $this->nullableFloat($signal['score'] ?? null);
            $present = array_key_exists('present', $signal) ? $this->boolValue($signal['present']) : null;

            $details[$field] = [
                'score' => $score,
                'bucket' => $this->confidenceBucket($score),
                'present' => $present,
                'status' => $this->safeToken($signal['status'] ?? null, 'n/a'),
                'reason' => $this->safeToken($signal['reason'] ?? null, 'n/a'),
                'source_path' => $this->safeToken($signal['source_path'] ?? null, 'n/a'),
            ];
        }

        return $details;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldDetails
     * @return list<string>
     */
    private function missingFields(array $fieldDetails): array
    {
        $missing = [];
        foreach ($fieldDetails as $field => $detail) {
            $present = $detail['present'] ?? null;
            $reason = $this->safeToken($detail['reason'] ?? null, 'n/a');
            $status = $this->safeToken($detail['status'] ?? null, 'n/a');

            if ($present === false || in_array($reason, ['missing_parsed_value'], true) || in_array($status, ['missing', 'unknown'], true)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function isLowConfidenceSignal(array $signal): bool
    {
        $score = $this->nullableFloat($signal['score'] ?? null);
        if ($score !== null && $score < self::LOW_CONFIDENCE_THRESHOLD) {
            return true;
        }

        $present = $signal['present'] ?? null;
        if ($present === false || $present === 0 || $present === '0' || $present === 'false') {
            return true;
        }

        $status = strtolower($this->safeToken($signal['status'] ?? null, ''));

        return in_array($status, ['low', 'missing', 'unknown'], true);
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

    /**
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    private function failureCodes(BiodataIntake $intake, array $signals): array
    {
        $fromIntake = $this->tokenList($intake->failure_codes_json);

        return $fromIntake !== [] ? $fromIntake : $this->tokenList($signals['failure_codes'] ?? []);
    }

    /**
     * @param  list<string>  $lowConfidenceFields
     * @param  array<string, mixed>  $signals
     * @return array{
     *     low_confidence_critical_fields: list<string>,
     *     low_confidence_important_fields: list<string>,
     *     low_confidence_optional_fields: list<string>,
     *     field_confidence_routing_severity: string,
     *     paid_vision_reasonable_for_field_confidence: bool
     * }
     */
    private function fieldClassification(array $lowConfidenceFields, array $signals, bool $hasRawOcrText): array
    {
        $computed = $this->fieldConfidenceClassifier->classify($lowConfidenceFields, $hasRawOcrText);
        $critical = $this->tokenList($signals['low_confidence_critical_fields'] ?? []);
        $important = $this->tokenList($signals['low_confidence_important_fields'] ?? []);
        $optional = $this->tokenList($signals['low_confidence_optional_fields'] ?? []);
        $severity = $this->safeToken($signals['field_confidence_routing_severity'] ?? null, '');
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
     * @param  array<string, array<string, mixed>>  $fieldDetails
     * @param  list<string>  $reasonCodes
     * @return list<string>
     */
    private function notes(?float $qualityScore, array $fieldDetails, array $reasonCodes, array $signals): array
    {
        $notes = [];
        if (in_array('field_confidence_low', $reasonCodes, true)) {
            $notes[] = 'routing_reason_field_confidence_low';
        }

        $hasMissingField = false;
        $hasLowScore = false;
        foreach ($fieldDetails as $detail) {
            if (($detail['present'] ?? null) === false || ($detail['reason'] ?? null) === 'missing_parsed_value') {
                $hasMissingField = true;
            }

            $score = $this->nullableFloat($detail['score'] ?? null);
            if ($score !== null && $score < self::LOW_CONFIDENCE_THRESHOLD) {
                $hasLowScore = true;
            }
        }

        if ($hasMissingField) {
            $notes[] = 'missing_field_signal';
        }

        if ($hasLowScore) {
            $notes[] = 'score_below_threshold';
        }

        if ($qualityScore !== null && $qualityScore >= 0.9) {
            $notes[] = 'high_quality_low_field_confidence';
        }
        $outcome = $this->safeToken($signals['critical_field_parser_proposal_outcome'] ?? null, '');
        if ($outcome !== '') {
            $notes[] = 'parser_proposal_'.$outcome;
        }

        return array_values(array_unique($notes));
    }

    /**
     * @param  array<string, mixed>  $recommendation
     */
    private function recommendedAction(array $recommendation): string
    {
        return $this->safeToken($recommendation['recommended_action'] ?? null, 'unknown');
    }

    private function tokenOption(string $option): ?string
    {
        $value = $this->option($option);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->safeToken($value);
    }

    private function confidenceBucket(?float $score): string
    {
        if ($score === null) {
            return 'unknown';
        }

        return match (true) {
            $score < 0.25 => '0-0.24',
            $score < 0.5 => '0.25-0.49',
            $score < 0.75 => '0.5-0.74',
            $score < 0.9 => '0.75-0.89',
            default => '0.9+',
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function fieldConfidenceDisplay(array $details): string
    {
        if ($details === []) {
            return '-';
        }

        $parts = [];
        foreach ($details as $field => $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $parts[] = $this->safeToken($field).':score='
                .($this->nullableFloat($detail['score'] ?? null) ?? 'n/a')
                .';present='.$this->yesNo($detail['present'] ?? null)
                .';reason='.$this->safeToken($detail['reason'] ?? null, 'n/a')
                .';path='.$this->safeToken($detail['source_path'] ?? null, 'n/a');
        }

        return implode(' | ', $parts) ?: '-';
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
            $rows[] = [$this->safeToken($key), $count];
        }

        return $rows;
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
            fn (mixed $item): string => $this->safeToken($item, ''),
            $value
        ), static fn (string $item): bool => $item !== ''));
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

        if (! preg_match('/^[A-Za-z0-9_.:\/+\-\[\]]+$/', $value)) {
            return '[redacted-text]';
        }

        return strlen($value) > 80 ? substr($value, 0, 77).'...' : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
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

    private function yesNo(mixed $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return $this->boolValue($value) ? 'yes' : 'no';
    }

    private function hasNonEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}
