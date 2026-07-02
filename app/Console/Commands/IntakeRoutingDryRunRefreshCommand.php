<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Services\Intake\IntakeRoutingTelemetryService;
use App\Services\Intake\IntakeSmartRoutingAdvisor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class IntakeRoutingDryRunRefreshCommand extends Command
{
    protected $signature = 'intake:routing-dry-run-refresh
        {--id= : Refresh one biodata intake id}
        {--limit=50 : Maximum latest intakes to inspect when --id is not supplied}
        {--all : Refresh intakes even when routing dry-run JSON already exists}
        {--dry-run : Print what would be refreshed without saving}
        {--json : Print a compact JSON summary instead of a table}';

    protected $description = 'Refresh smart-routing dry-run recommendation and telemetry for existing biodata intakes.';

    public function __construct(
        private readonly IntakeSmartRoutingAdvisor $advisor,
        private readonly IntakeRoutingTelemetryService $telemetry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $id = $this->option('id');
        $includeExisting = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');
        $limit = max(1, min(500, (int) $this->option('limit')));
        $hasExplicitId = $id !== null && trim((string) $id) !== '';

        $query = BiodataIntake::query()->withCount('ocrAttempts');

        if ($hasExplicitId) {
            $query->whereKey((int) $id);
        } else {
            $query
                ->where(function (Builder $query): void {
                    $query
                        ->whereNotNull('parsed_json')
                        ->orWhereNotNull('quality_summary_json')
                        ->orWhereNotNull('failure_codes_json')
                        ->orWhereNotNull('field_confidence_json')
                        ->orWhereHas('ocrAttempts');
                })
                ->latest('id')
                ->limit($limit);
        }

        if (! $hasExplicitId && ! $includeExisting) {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('routing_recommendation_json')
                    ->orWhereNull('routing_telemetry_json');
            });
        }

        $intakes = $query->get();

        if ($intakes->isEmpty()) {
            $message = 'No biodata intakes matched routing dry-run refresh criteria.';
            if ($json) {
                $this->line(json_encode([
                    'success' => true,
                    'dry_run' => $dryRun,
                    'count' => 0,
                    'rows' => [],
                    'message' => $message,
                ], JSON_UNESCAPED_SLASHES));
            } else {
                $this->warn($message);
            }

            return self::SUCCESS;
        }

        $rows = [];
        $saved = 0;
        $skipped = 0;

        foreach ($intakes as $intake) {
            $recommendation = $this->advisor->recommend($intake);
            $telemetry = $this->telemetry->forIntake($intake);
            $status = $dryRun ? 'dry_run' : 'saved';
            $alreadyHasRouting = is_array($intake->routing_recommendation_json)
                && is_array($intake->routing_telemetry_json);

            if (! $includeExisting && $alreadyHasRouting) {
                $status = 'skipped_existing';
                $skipped++;
            } elseif ($intake->intake_locked) {
                $status = 'skipped_locked';
                $skipped++;
            } elseif (! $dryRun) {
                $intake->forceFill([
                    'routing_recommendation_json' => $recommendation,
                    'routing_telemetry_json' => $telemetry,
                ])->save();
                $saved++;
            }

            $rows[] = [
                'intake_id' => (int) $intake->id,
                'recommended_action' => (string) ($recommendation['recommended_action'] ?? 'unknown'),
                'reason_codes' => implode(',', $this->stringList($recommendation['reason_codes'] ?? [])),
                'would_skip_paid_vision' => $this->boolLabel($recommendation['would_skip_paid_vision'] ?? false),
                'would_call_paid_vision' => $this->boolLabel($recommendation['would_call_paid_vision'] ?? false),
                'status' => $status,
            ];
        }

        if ($json) {
            $this->line(json_encode([
                'success' => true,
                'dry_run' => $dryRun,
                'saved' => $saved,
                'skipped' => $skipped,
                'count' => count($rows),
                'rows' => $rows,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table([
            'Intake',
            'Recommended action',
            'Reason codes',
            'Would skip paid vision',
            'Would call paid vision',
            'Status',
        ], array_map(static fn (array $row): array => [
            $row['intake_id'],
            $row['recommended_action'],
            $row['reason_codes'] !== '' ? $row['reason_codes'] : '—',
            $row['would_skip_paid_vision'],
            $row['would_call_paid_vision'],
            $row['status'],
        ], $rows));

        $this->info("Routing dry-run refresh complete. Saved: {$saved}; skipped: {$skipped}; matched: ".count($rows).'.');

        return self::SUCCESS;
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

    private function boolLabel(mixed $value): string
    {
        return (bool) $value ? 'yes' : 'no';
    }
}
