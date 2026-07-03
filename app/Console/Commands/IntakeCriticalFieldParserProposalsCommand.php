<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Services\Intake\IntakeCriticalFieldParserProposalService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IntakeCriticalFieldParserProposalsCommand extends Command
{
    private const CRITICAL_FIELDS = [
        'full_name',
        'date_of_birth',
        'primary_contact_number',
    ];

    protected $signature = 'intake:critical-field-parser-proposals
        {--limit=100 : Maximum latest intakes with stored routing/confidence data to inspect}
        {--json : Print the report as JSON}
        {--field= : Include only one critical field: full_name, date_of_birth, primary_contact_number}
        {--action= : Include only rows with this recommended_action}
        {--include-locked : Include locked intakes}
        {--show-safe-values : Show masked phone and unambiguous normalized DOB values}';

    protected $description = 'Read-only parser proposal report for missing critical fields from stored raw OCR text.';

    public function __construct(private readonly IntakeCriticalFieldParserProposalService $proposalService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $field = $this->fieldOption();
        if ($field === false) {
            return self::FAILURE;
        }

        $action = $this->tokenOption('action');
        $includeLocked = (bool) $this->option('include-locked');
        $showSafeValues = (bool) $this->option('show-safe-values');
        $rows = $this->loadIntakes($limit, $includeLocked)
            ->map(fn (BiodataIntake $intake): ?array => $this->proposalRow($intake, $field, $action, $showSafeValues))
            ->filter()
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'field' => $field,
                'action' => $action,
                'include_locked' => $includeLocked,
                'show_safe_values' => $showSafeValues,
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

    private function proposalRow(BiodataIntake $intake, ?string $fieldFilter, ?string $actionFilter, bool $showSafeValues): ?array
    {
        $recommendation = $this->arrayValue($intake->routing_recommendation_json);
        $signals = $this->arrayValue($recommendation['signals'] ?? []);
        $telemetry = $this->arrayValue($intake->routing_telemetry_json);
        $recommendedAction = $this->safeToken($recommendation['recommended_action'] ?? null, 'unknown');
        if ($actionFilter !== null && $recommendedAction !== $actionFilter) {
            return null;
        }

        $proposal = $this->proposalService->analyze(
            $intake,
            $signals,
            $fieldFilter !== null ? [$fieldFilter] : null
        );
        $criticalMissingFields = $this->tokenList($proposal['missing_critical_fields'] ?? []);
        if ($criticalMissingFields === []) {
            return null;
        }

        $proposalConfidence = $this->arrayValue($proposal['proposal_confidence'] ?? []);
        $outcome = $this->safeToken($proposal['parser_proposal_outcome'] ?? null, 'provider_candidate');

        return [
            'intake_id' => (int) $intake->id,
            'recommended_action' => $recommendedAction,
            'quality_score' => $this->qualityScore($intake, $signals, $telemetry),
            'critical_missing_fields' => $criticalMissingFields,
            'full_name_proposed' => $this->safeToken($proposal['full_name_proposed'] ?? null, 'no'),
            'full_name_candidate_line_count' => (int) ($proposal['full_name_candidate_line_count'] ?? 0),
            'full_name_word_count' => (int) ($proposal['full_name_word_count'] ?? 0),
            'full_name_confidence' => $this->confidence($proposalConfidence['full_name'] ?? null),
            'dob_proposed' => $this->safeToken($proposal['date_of_birth_proposed'] ?? null, 'no'),
            'dob_pattern_type' => $this->safeToken($proposal['date_of_birth_pattern_type'] ?? null, 'none'),
            'dob_confidence' => $this->confidence($proposalConfidence['date_of_birth'] ?? null),
            'dob_normalized' => $showSafeValues && ($proposal['date_of_birth_proposed'] ?? null) === 'yes'
                ? $this->safeToken($proposal['date_of_birth_normalized'] ?? null, null)
                : null,
            'phone_proposed' => $this->safeToken($proposal['primary_contact_number_proposed'] ?? null, 'no'),
            'phone_candidate_count' => (int) ($proposal['primary_contact_number_candidate_count'] ?? 0),
            'phone_confidence' => $this->confidence($proposalConfidence['primary_contact_number'] ?? null),
            'masked_phone' => $showSafeValues && ($proposal['primary_contact_number_proposed'] ?? null) === 'yes'
                ? $this->safeToken($proposal['primary_contact_number_masked'] ?? null, null)
                : null,
            'parser_proposal_outcome' => $outcome,
            'estimated_paid_vision_avoidable' => (bool) ($proposal['all_missing_critical_fields_have_safe_proposal'] ?? false),
            'missing_critical_fields_resolved_by_proposal' => (bool) ($proposal['missing_critical_fields_resolved_by_proposal'] ?? false),
            'has_ambiguous_critical_proposal' => (bool) ($proposal['has_ambiguous_critical_proposal'] ?? false),
            'raw_evidence_absent_fields' => $this->tokenList($proposal['raw_evidence_absent_fields'] ?? []),
            'suggested_next_action' => $outcome,
            'notes' => $this->notes($criticalMissingFields, $proposal, $outcome),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $fieldCounts = array_fill_keys(self::CRITICAL_FIELDS, 0);
        $confidenceCounts = [
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'none' => 0,
        ];
        $proposalsFound = 0;
        $noProposal = 0;
        $ambiguous = 0;
        $estimatedSarvamAvoidable = 0;
        $estimatedProviderNeeded = 0;

        foreach ($rows as $row) {
            $hasProposal = false;
            $hasAmbiguous = false;
            foreach (self::CRITICAL_FIELDS as $field) {
                if (! in_array($field, $this->tokenList($row['critical_missing_fields'] ?? []), true)) {
                    continue;
                }

                $proposal = $this->proposalStatusForField($row, $field);
                $confidence = $this->confidenceForField($row, $field);
                if ($proposal === 'yes') {
                    $fieldCounts[$field]++;
                    $hasProposal = true;
                } elseif ($proposal === 'ambiguous') {
                    $fieldCounts[$field]++;
                    $hasAmbiguous = true;
                }

                $confidenceCounts[$confidence] = ($confidenceCounts[$confidence] ?? 0) + 1;
            }

            if ($hasProposal) {
                $proposalsFound++;
            }
            if ($hasAmbiguous) {
                $ambiguous++;
            }
            if (! $hasProposal && ! $hasAmbiguous) {
                $noProposal++;
            }
            if (($row['parser_proposal_outcome'] ?? null) === 'parser_improvement_candidate') {
                $estimatedSarvamAvoidable++;
            }
            if (($row['parser_proposal_outcome'] ?? null) === 'provider_candidate') {
                $estimatedProviderNeeded++;
            }
        }

        return [
            'total_scanned' => $rows->count(),
            'proposals_found_count' => $proposalsFound,
            'no_proposal_count' => $noProposal,
            'ambiguous_count' => $ambiguous,
            'proposal_field_counts' => $fieldCounts,
            'confidence_counts' => $confidenceCounts,
            'estimated_sarvam_avoidable_count' => $estimatedSarvamAvoidable,
            'estimated_provider_needed_count' => $estimatedProviderNeeded,
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
            ['Proposals found', $summary['proposals_found_count'] ?? 0],
            ['No proposal', $summary['no_proposal_count'] ?? 0],
            ['Ambiguous', $summary['ambiguous_count'] ?? 0],
            ['Estimated Sarvam avoidable', $summary['estimated_sarvam_avoidable_count'] ?? 0],
            ['Estimated provider needed', $summary['estimated_provider_needed_count'] ?? 0],
        ]);

        $this->table(
            ['Proposal field', 'Count'],
            $this->countRows($this->arrayValue($summary['proposal_field_counts'] ?? []), 'none')
        );

        $this->table(
            ['Confidence', 'Count'],
            $this->countRows($this->arrayValue($summary['confidence_counts'] ?? []), 'none')
        );

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Action',
            'Quality',
            'Critical missing',
            'Name proposed',
            'Name lines',
            'Name words',
            'Name confidence',
            'DOB proposed',
            'DOB pattern',
            'DOB value',
            'DOB confidence',
            'Phone proposed',
            'Phone candidates',
            'Masked phone',
            'Phone confidence',
            'Proposal outcome',
            'Paid vision avoidable',
            'Resolved by proposal',
            'Ambiguous proposal',
            'Raw absent fields',
            'Notes',
        ], array_map(fn (array $row): array => [
            $row['intake_id'],
            $row['recommended_action'],
            $row['quality_score'] ?? 'n/a',
            implode(',', $this->tokenList($row['critical_missing_fields'] ?? [])) ?: '-',
            $this->safeToken($row['full_name_proposed'] ?? null, 'no'),
            $row['full_name_candidate_line_count'] ?? 0,
            $row['full_name_word_count'] ?? 0,
            $this->safeToken($row['full_name_confidence'] ?? null, 'none'),
            $this->safeToken($row['dob_proposed'] ?? null, 'no'),
            $this->safeToken($row['dob_pattern_type'] ?? null, 'none'),
            $this->safeToken($row['dob_normalized'] ?? null, 'hidden'),
            $this->safeToken($row['dob_confidence'] ?? null, 'none'),
            $this->safeToken($row['phone_proposed'] ?? null, 'no'),
            $row['phone_candidate_count'] ?? 0,
            $this->safeToken($row['masked_phone'] ?? null, 'hidden'),
            $this->safeToken($row['phone_confidence'] ?? null, 'none'),
            $this->safeToken($row['parser_proposal_outcome'] ?? null, 'provider_candidate'),
            $this->yesNo($row['estimated_paid_vision_avoidable'] ?? null),
            $this->yesNo($row['missing_critical_fields_resolved_by_proposal'] ?? null),
            $this->yesNo($row['has_ambiguous_critical_proposal'] ?? null),
            implode(',', $this->tokenList($row['raw_evidence_absent_fields'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['notes'] ?? [])) ?: '-',
        ], $rows));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function proposalStatusForField(array $row, string $field): string
    {
        return match ($field) {
            'full_name' => $this->safeToken($row['full_name_proposed'] ?? null, 'no'),
            'date_of_birth' => $this->safeToken($row['dob_proposed'] ?? null, 'no'),
            'primary_contact_number' => $this->safeToken($row['phone_proposed'] ?? null, 'no'),
            default => 'no',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function confidenceForField(array $row, string $field): string
    {
        $confidence = match ($field) {
            'full_name' => $row['full_name_confidence'] ?? 'none',
            'date_of_birth' => $row['dob_confidence'] ?? 'none',
            'primary_contact_number' => $row['phone_confidence'] ?? 'none',
            default => 'none',
        };

        return $this->confidence($confidence);
    }

    /**
     * @param  list<string>  $criticalMissingFields
     * @param  array<string, mixed>  $proposal
     * @return list<string>
     */
    private function notes(array $criticalMissingFields, array $proposal, string $outcome): array
    {
        $notes = ['read_only_stored_text_proposal'];
        $rawEvidenceAbsentFields = $this->tokenList($proposal['raw_evidence_absent_fields'] ?? []);
        $ambiguousFields = $this->tokenList($proposal['ambiguous_critical_fields'] ?? []);

        foreach ($criticalMissingFields as $field) {
            if (in_array($field, $rawEvidenceAbsentFields, true)) {
                $notes[] = $field.'_proposal_missing';
            } elseif (in_array($field, $ambiguousFields, true)) {
                $notes[] = $field.'_proposal_ambiguous';
            } else {
                $notes[] = $field.'_proposal_found';
            }
        }
        $notes[] = 'suggested_'.$outcome;

        return array_values(array_unique($notes));
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

    private function fieldOption(): string|null|false
    {
        $field = $this->tokenOption('field');
        if ($field === null) {
            return null;
        }

        if (! in_array($field, self::CRITICAL_FIELDS, true)) {
            $this->error('Invalid --field value. Allowed: '.implode(', ', self::CRITICAL_FIELDS).'.');

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

    private function safeToken(mixed $value, ?string $fallback = 'n/a'): ?string
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

        if (! preg_match('/^[A-Za-z0-9_.:\/+\-\[\]\*]+$/', $value)) {
            return '[redacted-text]';
        }

        return strlen($value) > 80 ? substr($value, 0, 77).'...' : $value;
    }

    private function confidence(mixed $value): string
    {
        return in_array($value, ['high', 'medium', 'low', 'none'], true) ? $value : 'none';
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

    private function nullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }
}
