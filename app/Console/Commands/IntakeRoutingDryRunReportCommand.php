<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class IntakeRoutingDryRunReportCommand extends Command
{
    private const ACTIONS = [
        'reuse_previous',
        'cheap_ocr_only',
        'call_sarvam',
        'manual_review',
        'unknown',
    ];

    protected $signature = 'intake:routing-dry-run-report
        {--limit=500 : Maximum latest intakes with routing dry-run data to scan}
        {--from= : Include intakes created on or after YYYY-MM-DD}
        {--to= : Include intakes created on or before YYYY-MM-DD}
        {--json : Print the summary as JSON}
        {--action= : Filter by recommended_action}
        {--include-locked : Include locked intakes in the scan}
        {--details : Include small safe sample diagnostics per recommended action}';

    protected $description = 'Show a read-only summary report for stored smart-routing dry-run data.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $action = $this->actionOption();

        if ($action === false) {
            return self::FAILURE;
        }

        $from = $this->dateOption('from', true);
        if ($from === false) {
            return self::FAILURE;
        }

        $to = $this->dateOption('to', false);
        if ($to === false) {
            return self::FAILURE;
        }

        $includeLocked = (bool) $this->option('include-locked');
        $includeDetails = (bool) $this->option('details');
        $baseQuery = BiodataIntake::query()
            ->select([
                'id',
                'created_at',
                'intake_locked',
                'routing_recommendation_json',
                'routing_telemetry_json',
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('routing_recommendation_json')
                    ->orWhereNotNull('routing_telemetry_json');
            });

        if ($from instanceof CarbonImmutable) {
            $baseQuery->where('created_at', '>=', $from);
        }

        if ($to instanceof CarbonImmutable) {
            $baseQuery->where('created_at', '<=', $to);
        }

        $lockedCount = (clone $baseQuery)->where('intake_locked', true)->count();
        $query = clone $baseQuery;

        if (! $includeLocked) {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('intake_locked')
                    ->orWhere('intake_locked', false);
            });
        }

        $intakes = $query
            ->latest('id')
            ->limit($limit)
            ->get();

        $intakes = $this->filterByAction($intakes, $action);

        $report = $this->summarize($intakes, [
            'limit' => $limit,
            'from' => $this->option('from') ?: null,
            'to' => $this->option('to') ?: null,
            'action' => $action,
            'include_locked' => $includeLocked,
            'details' => $includeDetails,
        ], $lockedCount, $includeLocked ? 0 : $lockedCount, $includeDetails);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderTables($report);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, BiodataIntake>  $intakes
     * @return Collection<int, BiodataIntake>
     */
    private function filterByAction(Collection $intakes, ?string $action): Collection
    {
        if ($action === null) {
            return $intakes->values();
        }

        return $intakes
            ->filter(fn (BiodataIntake $intake): bool => $this->recommendedAction($intake) === $action)
            ->values();
    }

    /**
     * @param  Collection<int, BiodataIntake>  $intakes
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function summarize(
        Collection $intakes,
        array $filters,
        int $lockedCount,
        int $lockedExcludedCount,
        bool $includeDetails,
    ): array
    {
        $actionCounts = array_fill_keys(self::ACTIONS, 0);
        $reasonCodeCounts = [];
        $sampleIdsByAction = array_fill_keys(self::ACTIONS, []);
        $detailsByAction = array_fill_keys(self::ACTIONS, []);
        $wouldSkipPaidVisionCount = 0;
        $wouldCallPaidVisionCount = 0;
        $unknownNoSignalCount = 0;
        $providerFailureCount = 0;
        $qualityScoreTotal = 0.0;
        $qualityScoreCount = 0;

        foreach ($intakes as $intake) {
            $recommendation = $this->arrayValue($intake->routing_recommendation_json);
            $telemetry = $this->arrayValue($intake->routing_telemetry_json);
            $action = $this->recommendedAction($intake);
            $reasonCodes = $this->stringList($recommendation['reason_codes'] ?? []);

            $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;

            if (count($sampleIdsByAction[$action] ?? []) < 10) {
                $sampleIdsByAction[$action][] = (int) $intake->id;
            }

            if ($includeDetails && count($detailsByAction[$action] ?? []) < 10) {
                $detailsByAction[$action][] = $this->detailRow($intake, $action, $recommendation, $telemetry, $reasonCodes);
            }

            foreach ($reasonCodes as $reasonCode) {
                $reasonCodeCounts[$reasonCode] = ($reasonCodeCounts[$reasonCode] ?? 0) + 1;
            }

            if ($this->boolValue($recommendation['would_skip_paid_vision'] ?? false)) {
                $wouldSkipPaidVisionCount++;
            }

            if ($this->boolValue($recommendation['would_call_paid_vision'] ?? false)) {
                $wouldCallPaidVisionCount++;
            }

            if ($action === 'unknown' || in_array('no_signal', $reasonCodes, true)) {
                $unknownNoSignalCount++;
            }

            $failedProviders = $this->numericValue($telemetry['failed_provider_count'] ?? null);
            if ($failedProviders !== null) {
                $providerFailureCount += (int) $failedProviders;
            }

            $qualityScore = $this->numericValue($telemetry['last_quality_score'] ?? null);
            if ($qualityScore !== null) {
                $qualityScoreTotal += $qualityScore;
                $qualityScoreCount++;
            }
        }

        arsort($reasonCodeCounts);

        $report = [
            'success' => true,
            'filters' => $filters,
            'total_scanned' => $intakes->count(),
            'action_counts' => $actionCounts,
            'reason_code_counts' => $reasonCodeCounts,
            'would_skip_paid_vision_count' => $wouldSkipPaidVisionCount,
            'would_call_paid_vision_count' => $wouldCallPaidVisionCount,
            'locked_count' => $lockedCount,
            'locked_excluded_count' => $lockedExcludedCount,
            'unknown_no_signal_count' => $unknownNoSignalCount,
            'provider_failure_count' => $providerFailureCount,
            'average_quality_score' => $qualityScoreCount > 0
                ? round($qualityScoreTotal / $qualityScoreCount, 4)
                : null,
            'sample_intake_ids_by_action' => $sampleIdsByAction,
        ];

        if ($includeDetails) {
            $report['details_by_action'] = $detailsByAction;
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderTables(array $report): void
    {
        $this->table(['Metric', 'Value'], [
            ['Total intakes scanned', $report['total_scanned']],
            ['Would skip paid vision', $report['would_skip_paid_vision_count']],
            ['Would call paid vision', $report['would_call_paid_vision_count']],
            ['Locked intakes', $report['locked_count']],
            ['Locked intakes excluded', $report['locked_excluded_count']],
            ['Unknown/no_signal', $report['unknown_no_signal_count']],
            ['Provider failures', $report['provider_failure_count']],
            ['Average quality score', $report['average_quality_score'] ?? 'n/a'],
        ]);

        $actionRows = [];
        foreach ($report['action_counts'] as $action => $count) {
            $actionRows[] = [
                $action,
                $count,
                implode(',', $report['sample_intake_ids_by_action'][$action] ?? []),
            ];
        }

        $this->table(['Recommended action', 'Count', 'Sample intake ids'], $actionRows);

        $reasonRows = [];
        foreach ($report['reason_code_counts'] as $reasonCode => $count) {
            $reasonRows[] = [$reasonCode, $count];
        }

        $this->table(['Reason code', 'Count'], $reasonRows ?: [['none', 0]]);

        if (! empty($report['details_by_action']) && is_array($report['details_by_action'])) {
            $this->renderDetailTables($report['details_by_action']);
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $detailsByAction
     */
    private function renderDetailTables(array $detailsByAction): void
    {
        foreach ($detailsByAction as $action => $rows) {
            if ($rows === []) {
                continue;
            }

            $this->line('Details: '.$action);
            $this->table([
                'Intake',
                'Recommended action',
                'Reason codes',
                'Signal summary',
                'Duplicate ref',
                'Quality',
                'OCR',
                'Cheap OCR',
                'Sarvam',
            ], array_map(static fn (array $row): array => [
                $row['intake_id'],
                $row['recommended_action'],
                implode(',', $row['reason_codes']),
                $row['signal_summary'],
                $row['duplicate_reference_intake_id'] ?? 'n/a',
                $row['quality_score'] ?? 'n/a',
                $row['ocr_attempt_count'] ?? 'n/a',
                $row['cheap_ocr_attempt_count'] ?? 'n/a',
                $row['sarvam_attempt_count'] ?? 'n/a',
            ], $rows));
        }
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @param  array<string, mixed>  $telemetry
     * @param  list<string>  $reasonCodes
     * @return array<string, mixed>
     */
    private function detailRow(
        BiodataIntake $intake,
        string $action,
        array $recommendation,
        array $telemetry,
        array $reasonCodes,
    ): array {
        $signals = $this->arrayValue($recommendation['signals'] ?? []);

        return [
            'intake_id' => (int) $intake->id,
            'recommended_action' => $action,
            'reason_codes' => $reasonCodes,
            'signal_summary' => $this->signalSummary($signals, $telemetry),
            'duplicate_reference_intake_id' => $this->nullableInt($signals['duplicate_reference_intake_id'] ?? null),
            'quality_score' => $this->numericValue($signals['quality_score'] ?? $telemetry['last_quality_score'] ?? null),
            'ocr_attempt_count' => $this->nullableInt($signals['ocr_attempt_count'] ?? null),
            'cheap_ocr_attempt_count' => $this->nullableInt($signals['cheap_ocr_attempt_count'] ?? $telemetry['cheap_ocr_attempt_count'] ?? null),
            'sarvam_attempt_count' => $this->nullableInt($signals['sarvam_attempt_count'] ?? $telemetry['sarvam_attempt_count'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $telemetry
     */
    private function signalSummary(array $signals, array $telemetry): string
    {
        $qualityScore = $this->numericValue($signals['quality_score'] ?? $telemetry['last_quality_score'] ?? null);
        $parts = [
            'source='.$this->summaryString($signals['duplicate_signal_source'] ?? null),
            'match='.$this->summaryString($signals['duplicate_match_type'] ?? null),
            'ref='.($this->nullableInt($signals['duplicate_reference_intake_id'] ?? null) ?? 'n/a'),
            'hash='.$this->summaryString($signals['matched_hash_type'] ?? null),
            'parsed='.$this->yesNo($signals['has_parsed_json'] ?? null),
            'raw='.$this->yesNo($signals['has_raw_ocr_text'] ?? null),
            'quality='.($qualityScore !== null ? (string) $qualityScore : 'n/a'),
            'ocr='.($this->nullableInt($signals['ocr_attempt_count'] ?? null) ?? 'n/a'),
            'cheap='.($this->nullableInt($signals['cheap_ocr_attempt_count'] ?? $telemetry['cheap_ocr_attempt_count'] ?? null) ?? 'n/a'),
            'sarvam='.($this->nullableInt($signals['sarvam_attempt_count'] ?? $telemetry['sarvam_attempt_count'] ?? null) ?? 'n/a'),
            'primary='.$this->yesNo($signals['primary_ocr_attempt_exists'] ?? null),
            'id_fp='.$this->yesNo($signals['identity_fingerprint_present'] ?? null),
            'text_hash='.$this->yesNo($signals['normalized_text_hash_present'] ?? null),
            'image_hash='.$this->yesNo($signals['image_hash_present'] ?? null),
        ];

        return implode('; ', $parts);
    }

    private function recommendedAction(BiodataIntake $intake): string
    {
        $recommendation = $this->arrayValue($intake->routing_recommendation_json);
        $action = is_scalar($recommendation['recommended_action'] ?? null)
            ? trim((string) $recommendation['recommended_action'])
            : '';

        return in_array($action, self::ACTIONS, true) ? $action : 'unknown';
    }

    private function actionOption(): string|null|false
    {
        $action = $this->option('action');

        if ($action === null || trim((string) $action) === '') {
            return null;
        }

        $action = trim((string) $action);

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error('Invalid --action value. Allowed: '.implode(', ', self::ACTIONS).'.');

            return false;
        }

        return $action;
    }

    private function dateOption(string $option, bool $startOfDay): CarbonImmutable|null|false
    {
        $value = $this->option($option);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->error("--{$option} must use YYYY-MM-DD.");

            return false;
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (Throwable) {
            $this->error("--{$option} must be a valid date.");

            return false;
        }

        return $startOfDay ? $date->startOfDay() : $date->endOfDay();
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

    private function numericValue(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function summaryString(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'n/a';
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : 'n/a';
    }

    private function yesNo(mixed $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return $this->boolValue($value) ? 'yes' : 'no';
    }
}
