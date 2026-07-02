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
        {--include-locked : Include locked intakes in the scan}';

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
        ], $lockedCount, $includeLocked ? 0 : $lockedCount);

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
    private function summarize(Collection $intakes, array $filters, int $lockedCount, int $lockedExcludedCount): array
    {
        $actionCounts = array_fill_keys(self::ACTIONS, 0);
        $reasonCodeCounts = [];
        $sampleIdsByAction = array_fill_keys(self::ACTIONS, []);
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

        return [
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
}
