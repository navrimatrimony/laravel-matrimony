<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Services\Intake\IntakeSmartRoutingPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class IntakeRoutingAcceptanceReportCommand extends Command
{
    private const ACTIONS = [
        'reuse_previous',
        'cheap_ocr_only',
        'call_sarvam',
        'manual_review',
        'unknown',
    ];

    protected $signature = 'intake:routing-acceptance-report
        {--limit=500 : Maximum latest intakes with stored routing data to scan}
        {--json : Print the report as JSON}
        {--fail-on-risk : Exit non-zero when acceptance status fails}
        {--max-paid-calls=12 : Maximum allowed would_call_paid_vision count}
        {--max-skip-calls=0 : Maximum allowed would_skip_paid_vision count}
        {--max-reuse-previous=0 : Maximum allowed reuse_previous action count}
        {--max-unknown=20 : Maximum allowed unknown action count}';

    protected $description = 'Read-only Phase 4A smart-routing acceptance baseline report.';

    public function __construct(private readonly IntakeSmartRoutingPolicy $policy)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $thresholds = $this->thresholds();
        if ($thresholds === false) {
            return self::FAILURE;
        }

        $limit = $thresholds['limit'];
        unset($thresholds['limit']);

        $intakes = $this->loadIntakes($limit);
        $report = $this->summarize($intakes, $limit, $thresholds);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderReport($report);
        }

        if ((bool) $this->option('fail-on-risk') && ! $report['acceptance']['passed']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, BiodataIntake>
     */
    private function loadIntakes(int $limit): Collection
    {
        return BiodataIntake::query()
            ->select([
                'id',
                'parse_status',
                'routing_recommendation_json',
                'routing_telemetry_json',
                'quality_summary_json',
                'created_at',
            ])
            ->whereNotNull('routing_recommendation_json')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, BiodataIntake>  $intakes
     * @param  array<string, int>  $thresholds
     * @return array<string, mixed>
     */
    private function summarize(Collection $intakes, int $limit, array $thresholds): array
    {
        $actionCounts = array_fill_keys(self::ACTIONS, 0);
        $wouldSkipPaidVisionCount = 0;
        $wouldCallPaidVisionCount = 0;
        $parserProposalAvoidableCount = 0;
        $parserProposalAmbiguousCount = 0;
        $rawEvidenceAbsentCount = 0;
        $lowQualityCheapOcrCount = 0;
        $policyEnabledCount = 0;
        $policyDryRunOnlyCounts = ['yes' => 0, 'no' => 0, 'unknown' => 0];
        $allowedLiveActionCount = 0;
        $providerFailureCount = 0;
        $callSarvamSampleIds = [];
        $manualReviewSampleIds = [];
        $unknownSampleIds = [];
        $parserProposalAvoidedSampleIds = [];
        $riskyRows = [];

        foreach ($intakes as $intake) {
            $recommendation = $this->arrayValue($intake->routing_recommendation_json);
            $telemetry = $this->arrayValue($intake->routing_telemetry_json);
            $signals = $this->arrayValue($recommendation['signals'] ?? []);
            $reasonCodes = $this->tokenList($recommendation['reason_codes'] ?? []);
            $action = $this->recommendedAction($recommendation);

            $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;
            $intakeId = (int) $intake->id;

            if ($action === 'call_sarvam') {
                $this->pushSample($callSarvamSampleIds, $intakeId);
            } elseif ($action === 'manual_review') {
                $this->pushSample($manualReviewSampleIds, $intakeId);
            } elseif ($action === 'unknown') {
                $this->pushSample($unknownSampleIds, $intakeId);
            }

            if ($this->boolValue($recommendation['would_skip_paid_vision'] ?? false)) {
                $wouldSkipPaidVisionCount++;
            }

            if ($this->boolValue($recommendation['would_call_paid_vision'] ?? false)) {
                $wouldCallPaidVisionCount++;
            }

            if ($this->parserProposalAvoidable($signals, $reasonCodes)) {
                $parserProposalAvoidableCount++;
                $this->pushSample($parserProposalAvoidedSampleIds, $intakeId);
            }

            if ($this->parserProposalAmbiguous($signals, $reasonCodes)) {
                $parserProposalAmbiguousCount++;
            }

            if ($this->rawEvidenceAbsent($signals, $reasonCodes)) {
                $rawEvidenceAbsentCount++;
            }

            if (in_array('low_quality_cheap_ocr', $reasonCodes, true)) {
                $lowQualityCheapOcrCount++;
            }

            $policySummary = $this->policySummary($recommendation);
            if (($policySummary['enabled'] ?? null) === true) {
                $policyEnabledCount++;
            }
            $dryRunLabel = match ($policySummary['dry_run_only'] ?? null) {
                true => 'yes',
                false => 'no',
                default => 'unknown',
            };
            $policyDryRunOnlyCounts[$dryRunLabel]++;

            $allowedLiveAction = $this->safeToken($policySummary['allowed_live_action'] ?? null, 'none');
            if ($allowedLiveAction !== 'none') {
                $allowedLiveActionCount++;
            }

            $rowProviderFailures = $this->providerFailureCount($telemetry, $signals, $reasonCodes);
            $providerFailureCount += $rowProviderFailures;

            $rowRiskCodes = $this->rowRiskCodes(
                $recommendation,
                $action,
                $policySummary,
                $rowProviderFailures
            );
            if ($rowRiskCodes !== []) {
                $riskyRows[] = [
                    'intake_id' => $intakeId,
                    'recommended_action' => $action,
                    'risk_codes' => $rowRiskCodes,
                    'reason_codes' => $reasonCodes,
                ];
            }
        }

        $summary = [
            'total_scanned' => $intakes->count(),
            'action_counts' => $actionCounts,
            'would_skip_paid_vision_count' => $wouldSkipPaidVisionCount,
            'would_call_paid_vision_count' => $wouldCallPaidVisionCount,
            'parser_proposal_avoidable_count' => $parserProposalAvoidableCount,
            'parser_proposal_ambiguous_count' => $parserProposalAmbiguousCount,
            'raw_evidence_absent_count' => $rawEvidenceAbsentCount,
            'low_quality_cheap_ocr_count' => $lowQualityCheapOcrCount,
            'policy_enabled_count' => $policyEnabledCount,
            'policy_dry_run_only_counts' => $policyDryRunOnlyCounts,
            'allowed_live_action_count' => $allowedLiveActionCount,
            'provider_failure_count' => $providerFailureCount,
        ];
        $acceptance = $this->acceptance($summary, $thresholds);

        return [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'fail_on_risk' => (bool) $this->option('fail-on-risk'),
            ],
            'thresholds' => $thresholds,
            'summary' => $summary,
            'acceptance' => $acceptance,
            'risk_details' => [
                'risky_rows' => array_slice($riskyRows, 0, 25),
                'call_sarvam_sample_ids' => $callSarvamSampleIds,
                'manual_review_sample_ids' => $manualReviewSampleIds,
                'unknown_sample_ids' => $unknownSampleIds,
                'parser_proposal_avoided_sample_ids' => $parserProposalAvoidedSampleIds,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, int>  $thresholds
     * @return array<string, mixed>
     */
    private function acceptance(array $summary, array $thresholds): array
    {
        $risks = [];
        $this->thresholdRisk($risks, 'would_skip_paid_vision', (int) $summary['would_skip_paid_vision_count'], $thresholds['max_skip_calls']);
        $this->thresholdRisk($risks, 'reuse_previous', (int) $summary['action_counts']['reuse_previous'], $thresholds['max_reuse_previous']);
        $this->thresholdRisk($risks, 'would_call_paid_vision', (int) $summary['would_call_paid_vision_count'], $thresholds['max_paid_calls']);
        $this->thresholdRisk($risks, 'unknown', (int) $summary['action_counts']['unknown'], $thresholds['max_unknown']);

        if ((int) $summary['policy_enabled_count'] > 0) {
            $risks[] = [
                'code' => 'policy_live_enabled',
                'actual' => (int) $summary['policy_enabled_count'],
                'max' => 0,
            ];
        }

        if ((int) $summary['allowed_live_action_count'] > 0) {
            $risks[] = [
                'code' => 'allowed_live_action_present',
                'actual' => (int) $summary['allowed_live_action_count'],
                'max' => 0,
            ];
        }

        if ((int) $summary['provider_failure_count'] > 0) {
            $risks[] = [
                'code' => 'provider_failures_present',
                'actual' => (int) $summary['provider_failure_count'],
                'max' => 0,
            ];
        }

        return [
            'status' => $risks === [] ? 'pass' : 'fail',
            'passed' => $risks === [],
            'risks' => $risks,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $risks
     */
    private function thresholdRisk(array &$risks, string $code, int $actual, int $max): void
    {
        if ($actual <= $max) {
            return;
        }

        $risks[] = [
            'code' => $code.'_exceeds_threshold',
            'actual' => $actual,
            'max' => $max,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);
        $acceptance = $this->arrayValue($report['acceptance'] ?? []);
        $riskDetails = $this->arrayValue($report['risk_details'] ?? []);

        $this->table(['Metric', 'Value'], [
            ['Total scanned', $summary['total_scanned'] ?? 0],
            ['Would skip paid vision', $summary['would_skip_paid_vision_count'] ?? 0],
            ['Would call paid vision', $summary['would_call_paid_vision_count'] ?? 0],
            ['Parser proposal avoidable', $summary['parser_proposal_avoidable_count'] ?? 0],
            ['Parser proposal ambiguous', $summary['parser_proposal_ambiguous_count'] ?? 0],
            ['Raw evidence absent', $summary['raw_evidence_absent_count'] ?? 0],
            ['Low quality cheap OCR', $summary['low_quality_cheap_ocr_count'] ?? 0],
            ['Policy enabled', $summary['policy_enabled_count'] ?? 0],
            ['Policy dry run only', $this->countPair($this->arrayValue($summary['policy_dry_run_only_counts'] ?? []))],
            ['Allowed live actions', $summary['allowed_live_action_count'] ?? 0],
            ['Provider failures', $summary['provider_failure_count'] ?? 0],
            ['Acceptance status', $this->safeToken($acceptance['status'] ?? null, 'fail')],
        ]);

        $this->table(
            ['Recommended action', 'Count'],
            $this->countRows($this->arrayValue($summary['action_counts'] ?? []), 'none')
        );

        $this->table(
            ['Risk', 'Actual', 'Max'],
            array_map(
                fn (array $risk): array => [
                    $this->safeToken($risk['code'] ?? null),
                    $risk['actual'] ?? 'n/a',
                    $risk['max'] ?? 'n/a',
                ],
                $this->arrayValue($acceptance['risks'] ?? [])
            ) ?: [['none', 0, 0]]
        );

        $this->table(['Sample', 'Intake IDs'], [
            ['call_sarvam', implode(',', $this->intList($riskDetails['call_sarvam_sample_ids'] ?? [])) ?: '-'],
            ['manual_review', implode(',', $this->intList($riskDetails['manual_review_sample_ids'] ?? [])) ?: '-'],
            ['unknown', implode(',', $this->intList($riskDetails['unknown_sample_ids'] ?? [])) ?: '-'],
            ['parser_proposal_avoided', implode(',', $this->intList($riskDetails['parser_proposal_avoided_sample_ids'] ?? [])) ?: '-'],
        ]);

        $riskyRows = $this->arrayValue($riskDetails['risky_rows'] ?? []);
        $this->table(['Risky intake', 'Action', 'Risks', 'Reasons'], array_map(fn (array $row): array => [
            $row['intake_id'] ?? 'n/a',
            $this->safeToken($row['recommended_action'] ?? null),
            implode(',', $this->tokenList($row['risk_codes'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['reason_codes'] ?? [])) ?: '-',
        ], $riskyRows) ?: [['none', '-', '-', '-']]);
    }

    /**
     * @return array{limit: int, max_paid_calls: int, max_skip_calls: int, max_reuse_previous: int, max_unknown: int}|false
     */
    private function thresholds(): array|false
    {
        $options = [
            'limit' => 'limit',
            'max_paid_calls' => 'max-paid-calls',
            'max_skip_calls' => 'max-skip-calls',
            'max_reuse_previous' => 'max-reuse-previous',
            'max_unknown' => 'max-unknown',
        ];
        $thresholds = [];
        foreach ($options as $key => $option) {
            $value = $this->nonNegativeIntOption($option);
            if ($value === false) {
                return false;
            }
            $thresholds[$key] = $key === 'limit' ? max(1, $value) : $value;
        }

        return $thresholds;
    }

    private function nonNegativeIntOption(string $option): int|false
    {
        $value = $this->option($option);
        if (! is_numeric($value) || (int) $value < 0) {
            $this->error("--{$option} must be a non-negative integer.");

            return false;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $recommendation
     */
    private function recommendedAction(array $recommendation): string
    {
        $action = $this->safeToken($recommendation['recommended_action'] ?? null, 'unknown');

        return in_array($action, self::ACTIONS, true) ? $action : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $reasonCodes
     */
    private function parserProposalAvoidable(array $signals, array $reasonCodes): bool
    {
        return $this->boolValue($signals['estimated_paid_vision_avoidable'] ?? false)
            || in_array('critical_field_parser_proposal_available', $reasonCodes, true);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $reasonCodes
     */
    private function parserProposalAmbiguous(array $signals, array $reasonCodes): bool
    {
        return $this->boolValue($signals['has_ambiguous_critical_proposal'] ?? false)
            || in_array('critical_field_parser_proposal_ambiguous', $reasonCodes, true);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $reasonCodes
     */
    private function rawEvidenceAbsent(array $signals, array $reasonCodes): bool
    {
        return $this->tokenList($signals['critical_field_parser_raw_evidence_absent_fields'] ?? []) !== []
            || in_array('critical_field_raw_evidence_absent', $reasonCodes, true);
    }

    /**
     * @param  array<string, mixed>  $telemetry
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $reasonCodes
     */
    private function providerFailureCount(array $telemetry, array $signals, array $reasonCodes): int
    {
        $count = $this->nullableInt($telemetry['failed_provider_count'] ?? null)
            ?? $this->nullableInt($signals['failed_provider_count'] ?? null)
            ?? 0;

        return max($count, in_array('provider_error', $reasonCodes, true) ? 1 : 0);
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array{enabled: ?bool, dry_run_only: ?bool, allowed_live_action: string, blocked_reason: string}
     */
    private function policySummary(array $recommendation): array
    {
        $evaluated = $this->policy->evaluate($recommendation);
        $stored = $this->storedPolicySummary($recommendation);

        return [
            'enabled' => $stored['enabled'] ?? $this->nullableBool($evaluated['enabled'] ?? null),
            'dry_run_only' => $stored['dry_run_only'] ?? $this->nullableBool($evaluated['dry_run_only'] ?? null),
            'allowed_live_action' => $this->safeToken(
                $stored['allowed_live_action'] ?? $evaluated['allowed_live_action'] ?? null,
                'none'
            ),
            'blocked_reason' => $this->safeToken(
                $stored['blocked_reason'] ?? $evaluated['blocked_reason'] ?? null,
                'none'
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array{enabled?: ?bool, dry_run_only?: ?bool, allowed_live_action?: ?string, blocked_reason?: ?string}
     */
    private function storedPolicySummary(array $recommendation): array
    {
        $policy = $this->arrayValue($recommendation['policy'] ?? []);
        if ($policy === []) {
            $policy = $this->arrayValue($recommendation['policy_evaluation'] ?? []);
        }
        if ($policy === []) {
            $policy = $this->arrayValue($recommendation['policy_summary'] ?? []);
        }

        if ($policy === []) {
            return [];
        }

        return [
            'enabled' => array_key_exists('enabled', $policy) ? $this->nullableBool($policy['enabled']) : null,
            'dry_run_only' => array_key_exists('dry_run_only', $policy) ? $this->nullableBool($policy['dry_run_only']) : null,
            'allowed_live_action' => $this->safeToken($policy['allowed_live_action'] ?? null, 'none'),
            'blocked_reason' => $this->safeToken($policy['blocked_reason'] ?? null, 'none'),
        ];
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @param  array<string, mixed>  $policySummary
     * @return list<string>
     */
    private function rowRiskCodes(array $recommendation, string $action, array $policySummary, int $providerFailures): array
    {
        $risks = [];
        if ($this->boolValue($recommendation['would_skip_paid_vision'] ?? false)) {
            $risks[] = 'would_skip_paid_vision';
        }
        if ($action === 'reuse_previous') {
            $risks[] = 'reuse_previous';
        }
        if (($policySummary['enabled'] ?? null) === true) {
            $risks[] = 'policy_live_enabled';
        }
        if (($policySummary['allowed_live_action'] ?? 'none') !== 'none') {
            $risks[] = 'allowed_live_action_present';
        }
        if ($providerFailures > 0) {
            $risks[] = 'provider_failure';
        }

        return array_values(array_unique($risks));
    }

    /**
     * @param  list<int>  $samples
     */
    private function pushSample(array &$samples, int $id): void
    {
        if (count($samples) >= 10) {
            return;
        }

        $samples[] = $id;
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
     * @param  array<string, mixed>  $counts
     */
    private function countPair(array $counts): string
    {
        return 'yes='.((int) ($counts['yes'] ?? 0))
            .'; no='.((int) ($counts['no'] ?? 0))
            .'; unknown='.((int) ($counts['unknown'] ?? 0));
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

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?int => is_numeric($item) ? (int) $item : null,
            $value
        ), static fn (?int $item): bool => $item !== null));
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

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
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

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
