<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Services\Intake\IntakeQualitySignalService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class IntakeLegacyQualityBackfillCommand extends Command
{
    protected $signature = 'intake:legacy-quality-backfill
        {--id= : Backfill one biodata intake id}
        {--limit=100 : Maximum latest intakes to inspect when --id is not supplied}
        {--dry-run : Print what would be backfilled without saving}
        {--all : Refresh quality signal fields even when they already exist}
        {--json : Print a compact JSON summary instead of a table}';

    protected $description = 'Backfill legacy biodata intake quality signals from stored raw OCR text and parsed JSON only.';

    public function __construct(private readonly IntakeQualitySignalService $qualitySignals)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $id = $this->option('id');
        $hasExplicitId = $id !== null && trim((string) $id) !== '';
        $refreshExisting = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');
        $limit = max(1, min(500, (int) $this->option('limit')));

        if ($hasExplicitId && (int) $id < 1) {
            return $this->failWithMessage('Intake id must be a positive integer.', $json);
        }

        $query = BiodataIntake::query();

        if ($hasExplicitId) {
            $query->whereKey((int) $id);
        } else {
            $query->latest('id')->limit($limit);
        }

        if (! $refreshExisting) {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('quality_summary_json')
                    ->orWhereNull('field_confidence_json');
            });
        }

        $intakes = $query->get();

        if ($intakes->isEmpty()) {
            $message = 'No biodata intakes matched legacy quality backfill criteria.';
            if ($json) {
                $this->line(json_encode([
                    'success' => true,
                    'dry_run' => $dryRun,
                    'saved' => 0,
                    'skipped' => 0,
                    'skipped_locked' => 0,
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
        $skippedLocked = 0;

        foreach ($intakes as $intake) {
            $computed = $this->computedSignalAttributes($intake);
            $updates = $this->updatesFor($intake, $computed, $refreshExisting);
            $status = $dryRun ? 'dry_run' : 'saved';

            if ($updates === []) {
                $status = 'skipped_existing';
                $skipped++;
            } elseif ($intake->intake_locked && ! $dryRun) {
                $status = 'skipped_locked';
                $skipped++;
                $skippedLocked++;
            } elseif (! $dryRun) {
                try {
                    $this->saveSignalUpdates($intake, $updates);
                    $saved++;
                } catch (RuntimeException $exception) {
                    if (! str_contains($exception->getMessage(), 'Locked biodata intake cannot be updated')) {
                        throw $exception;
                    }

                    $status = 'skipped_locked';
                    $skipped++;
                    $skippedLocked++;
                }
            }

            $rows[] = $this->rowFor($intake, $computed, $updates, $status);
        }

        if ($json) {
            $this->line(json_encode([
                'success' => true,
                'dry_run' => $dryRun,
                'saved' => $saved,
                'skipped' => $skipped,
                'skipped_locked' => $skippedLocked,
                'count' => count($rows),
                'rows' => $rows,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table([
            'Intake',
            'Raw',
            'Parsed',
            'Quality',
            'Present fields',
            'Failure codes',
            'Updated fields',
            'Status',
        ], array_map(static fn (array $row): array => [
            $row['intake_id'],
            $row['has_raw_ocr_text'] ? 'yes' : 'no',
            $row['has_parsed_json'] ? 'yes' : 'no',
            $row['quality_score'] ?? 'n/a',
            $row['present_field_count'],
            $row['failure_codes'] !== [] ? implode(',', $row['failure_codes']) : '-',
            $row['updated_fields'] !== [] ? implode(',', $row['updated_fields']) : '-',
            $row['status'],
        ], $rows));

        $this->info("Legacy quality backfill complete. Saved: {$saved}; skipped: {$skipped}; skipped_locked: {$skippedLocked}; matched: ".count($rows).'.');

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     quality_summary_json: array<string, mixed>,
     *     failure_codes_json: list<string>,
     *     field_confidence_json: array<string, mixed>|null
     * }
     */
    private function computedSignalAttributes(BiodataIntake $intake): array
    {
        $parsedJson = is_array($intake->parsed_json) ? $intake->parsed_json : null;
        $signalText = $this->signalText($intake, $parsedJson);

        return [
            'quality_summary_json' => $this->qualitySignals->qualitySummary($signalText),
            'failure_codes_json' => $this->qualitySignals->failureCodes($signalText, $parsedJson),
            'field_confidence_json' => is_array($parsedJson)
                ? $this->qualitySignals->fieldConfidence($parsedJson)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $parsedJson
     */
    private function signalText(BiodataIntake $intake, ?array $parsedJson): string
    {
        $rawText = trim((string) $intake->raw_ocr_text);
        if ($rawText !== '') {
            return $rawText;
        }

        if (! is_array($parsedJson) || $parsedJson === []) {
            return '';
        }

        return implode("\n", array_slice($this->parsedScalarValues($parsedJson), 0, 200));
    }

    /**
     * @param  array<string, mixed>  $parsedJson
     * @return list<string>
     */
    private function parsedScalarValues(array $parsedJson): array
    {
        $values = [];
        array_walk_recursive($parsedJson, static function (mixed $value) use (&$values): void {
            if (! is_string($value) && ! is_numeric($value)) {
                return;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $values[] = $value;
            }
        });

        return array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $computed
     * @return array<string, mixed>
     */
    private function updatesFor(BiodataIntake $intake, array $computed, bool $refreshExisting): array
    {
        $updates = [];
        foreach ([
            'quality_summary_json',
            'failure_codes_json',
            'field_confidence_json',
        ] as $field) {
            if ($refreshExisting || $intake->{$field} === null) {
                $updates[$field] = $computed[$field];
            }
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function saveSignalUpdates(BiodataIntake $intake, array $updates): void
    {
        $usesTimestamps = $intake->timestamps;
        $intake->timestamps = false;

        try {
            $intake->forceFill($updates)->save();
        } finally {
            $intake->timestamps = $usesTimestamps;
        }
    }

    /**
     * @param  array<string, mixed>  $computed
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function rowFor(BiodataIntake $intake, array $computed, array $updates, string $status): array
    {
        $qualitySummary = is_array($computed['quality_summary_json'] ?? null) ? $computed['quality_summary_json'] : [];
        $failureCodes = is_array($computed['failure_codes_json'] ?? null) ? $computed['failure_codes_json'] : [];
        $fieldConfidence = is_array($computed['field_confidence_json'] ?? null) ? $computed['field_confidence_json'] : [];

        return [
            'intake_id' => (int) $intake->id,
            'has_raw_ocr_text' => trim((string) $intake->raw_ocr_text) !== '',
            'has_parsed_json' => is_array($intake->parsed_json) && $intake->parsed_json !== [],
            'quality_score' => isset($qualitySummary['score']) && is_numeric($qualitySummary['score'])
                ? (float) $qualitySummary['score']
                : null,
            'present_field_count' => $this->presentFieldCount($fieldConfidence),
            'failure_codes' => array_values(array_filter(array_map(
                static fn (mixed $code): string => is_scalar($code) ? trim((string) $code) : '',
                $failureCodes,
            ), static fn (string $code): bool => $code !== '')),
            'updated_fields' => array_keys($updates),
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $fieldConfidence
     */
    private function presentFieldCount(array $fieldConfidence): int
    {
        return count(array_filter(
            $fieldConfidence,
            static fn (mixed $row): bool => is_array($row) && ($row['present'] ?? false) === true,
        ));
    }

    private function failWithMessage(string $message, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'success' => false,
                'message' => $message,
            ], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
